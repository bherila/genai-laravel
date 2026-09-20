<?php

namespace Bherila\GenAiLaravel\FileConversion;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileLimits;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a document conversion in a child process with an enforced memory cap and
 * wall-clock limit, so hostile input kills the child rather than the worker.
 *
 * ConversionLimits is explicit that it is not a security boundary: only
 * maxInputBytes applies before the parser opens the file, and XLSX and DOCX are
 * ZIP containers whose contents both parsers materialise in-process before any
 * traversal ceiling can run. ZipBounds closes the common case by refusing a
 * bomb from its own central directory, but a parser can still be pathological
 * on input that declares nothing unusual, and neither PhpSpreadsheet nor
 * PhpWord is interruptible once inside a parse.
 *
 * A separate process with a hard address-space cap is the only thing that
 * bounds that, because the kernel enforces it rather than the library. A child
 * that is killed is a *rejected upload*, not an internal error, and that is how
 * its failure is reported.
 *
 * **This is opt-in and it fails loudly.** It depends on a PHP binary, a POSIX
 * shell and `ulimit -v`, none of which exist everywhere — Windows in
 * particular. Rather than silently running in-process when it cannot isolate,
 * which would hand back the safety property it was chosen for, it throws.
 * Check {@see isSupported()} if you need to decide at runtime.
 */
final readonly class IsolatedConverter
{
    /**
     * @param  int  $memoryLimitBytes  Address-space cap for the child. The parser needs
     *                                 room for the workbook it is reading, so this is
     *                                 generous next to a document's own size; the point
     *                                 is that it is finite and enforced by the kernel.
     */
    public function __construct(
        private ConversionLimits $limits = new ConversionLimits,
        private int $memoryLimitBytes = 536_870_912, // 512 MB
        private ?string $phpBinary = null,
    ) {}

    /**
     * Whether this host can enforce isolation at all.
     *
     * A false here means the guarantee is unavailable, not that conversion is:
     * the caller decides between refusing the upload and converting in-process
     * with the weaker ceilings. Making that choice explicit is the point.
     */
    public static function isSupported(): bool
    {
        return self::isAvailable()
            && ! self::isWindows()
            && (new ExecutableFinder)->find('sh') !== null
            && self::resolvePhpBinary() !== null;
    }

    /**
     * Whether symfony/process is installed.
     *
     * Optional, like the converters themselves: this package depends on
     * illuminate components rather than laravel/framework, so a lean install
     * may not have it.
     */
    public static function isAvailable(): bool
    {
        return class_exists(Process::class);
    }

    /** @throws GenAiFatalException|GenAiFileTooLargeException */
    public function spreadsheetToText(string $base64, string $mimeType): string
    {
        return $this->run('spreadsheet', $base64, $mimeType);
    }

    /** @throws GenAiFatalException|GenAiFileTooLargeException */
    public function wordDocumentToPdf(string $base64, string $mimeType): string
    {
        return $this->run('word', $base64, $mimeType);
    }

    private function run(string $converter, string $base64, string $mimeType): string
    {
        if (! self::isAvailable()) {
            throw new GenAiFatalException(
                'IsolatedConverter requires symfony/process. Install it with: composer require symfony/process'
            );
        }

        $php = $this->phpBinary ?? self::resolvePhpBinary();
        if ($php === null || self::isWindows()) {
            throw new GenAiFatalException(
                'IsolatedConverter cannot enforce isolation on this host: it needs a PHP CLI binary '
                .'and a POSIX shell with ulimit. Convert in-process with SpreadsheetToText or '
                .'WordDocumentToPdf if the weaker in-process ceilings are acceptable for this input, '
                .'or refuse the upload — but do not assume this ran isolated.',
            );
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new GenAiFatalException('IsolatedConverter: input is not valid base64.');
        }

        $input = tempnam(sys_get_temp_dir(), 'genai_isolated_');
        if ($input === false) {
            throw new GenAiFatalException('IsolatedConverter: failed to allocate a temp file.');
        }

        try {
            if (file_put_contents($input, $bytes) === false) {
                throw new GenAiFatalException('IsolatedConverter: failed to write the temp file.');
            }

            $process = Process::fromShellCommandline(
                // ulimit caps the child's address space before exec, so the cap
                // is the kernel's, not PHP's: memory_limit can be raised from
                // inside a script, and this cannot.
                'ulimit -v $GENAI_KB 2>/dev/null; exec "$GENAI_PHP" "$GENAI_RUNNER" "$GENAI_KIND" "$GENAI_IN" "$GENAI_MIME" "$GENAI_LIMITS"',
            );
            $process
                ->setEnv([
                    'GENAI_KB' => (string) intdiv($this->memoryLimitBytes, 1024),
                    'GENAI_PHP' => $php,
                    'GENAI_RUNNER' => self::runnerPath(),
                    'GENAI_KIND' => $converter,
                    'GENAI_IN' => $input,
                    'GENAI_MIME' => $mimeType,
                    'GENAI_LIMITS' => $this->limitsJson(),
                ])
                // A second of slack over the conversion budget, so the child's
                // own deadline is what normally stops it and this is the
                // backstop for a parse that will not yield.
                ->setTimeout($this->limits->maxSeconds + 1.0);

            try {
                $process->run();
            } catch (ProcessSignaledException|ProcessTimedOutException) {
                // The kernel stopped the child: the address-space cap or the
                // wall-clock limit. Symfony surfaces that as an exception out
                // of run() rather than an exit code, and under ulimit -v an
                // allocation failure inside the parser usually arrives as
                // SIGSEGV rather than a clean out-of-memory. Either way the
                // document is rejected and this process is untouched.
                throw $this->rejected();
            }

            return $this->resultOf($process);
        } finally {
            @unlink($input);
        }
    }

    private function resultOf(Process $process): string
    {
        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        // Exit 1 is the runner reporting a rejection it recognised. Rethrow the
        // same class a direct conversion would have thrown, so an isolated call
        // is a drop-in for an in-process one.
        if ($process->getExitCode() === 1) {
            [$class, $message] = array_pad(explode("\n", $process->getErrorOutput(), 2), 2, '');
            if ($class === GenAiFileTooLargeException::class) {
                throw new GenAiFileTooLargeException($message);
            }

            throw new GenAiFatalException($message !== '' ? $message : 'The document could not be converted.');
        }

        // Anything else means the child died without reporting a reason it
        // recognised, which is the same outcome as being signaled.
        throw $this->rejected();
    }

    /**
     * The upload exhausted the limits and was stopped.
     *
     * Deliberately a GenAiFileTooLargeException rather than a fatal one: the
     * document was refused on resources, the caller's code is fine, and this
     * process is still healthy — which is the entire reason for the child.
     */
    private function rejected(): GenAiFileTooLargeException
    {
        return new GenAiFileTooLargeException(sprintf(
            'The document exhausted the isolated conversion limits (%s of memory, %.0fs) and was stopped. '
            .'It is rejected rather than converted; the worker was not affected.',
            FileLimits::humanBytes($this->memoryLimitBytes),
            $this->limits->maxSeconds,
        ));
    }

    private function limitsJson(): string
    {
        return json_encode([
            'maxInputBytes' => $this->limits->maxInputBytes,
            'maxOutputBytes' => $this->limits->maxOutputBytes,
            'maxRowsPerSheet' => $this->limits->maxRowsPerSheet,
            'maxCells' => $this->limits->maxCells,
            'maxSeconds' => $this->limits->maxSeconds,
            'maxArchiveEntries' => $this->limits->maxArchiveEntries,
            'maxUncompressedBytes' => $this->limits->maxUncompressedBytes,
            'maxCompressionRatio' => $this->limits->maxCompressionRatio,
        ], JSON_THROW_ON_ERROR);
    }

    private static function runnerPath(): string
    {
        return dirname(__DIR__, 2).'/bin/genai-convert';
    }

    /**
     * The CLI binary that can run the runner.
     *
     * PHP_BINARY is only that under the CLI SAPI. In a web process it is
     * php-fpm or the module host, which cannot execute a script, so the worker
     * that actually accepts uploads has to go looking on PATH.
     */
    private static function resolvePhpBinary(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        return (new ExecutableFinder)->find('php');
    }

    private static function isWindows(): bool
    {
        return str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');
    }
}
