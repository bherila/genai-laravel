<?php

namespace Bherila\GenAiLaravel\FileConversion;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileLimits;

/**
 * Best-effort resource ceilings for Office-document conversion.
 *
 * These bound the *accidental* cases — the 400,000-row export somebody uploads
 * by mistake, the sparse sheet with a cell at XFD1048576, the conversion that
 * would otherwise pin a worker for ten minutes. Defaults are generous for real
 * business documents and well below what it takes to hurt a process.
 *
 * Most of them are **not a security boundary**. $maxInputBytes and the archive
 * bounds below apply before the document reaches the parser; everything else is
 * checked while walking a workbook PhpSpreadsheet has already opened, or against
 * output PhpWord has already rendered, and neither library is interruptible once
 * inside a parse.
 *
 * The archive bounds close the case that used to be unanswerable here. XLSX,
 * DOCX and ODS are ZIP containers, and both libraries materialise their contents
 * in-process, so a decompression bomb sized just under $maxInputBytes exhausted
 * memory before any traversal ceiling could run. {@see assertArchiveWithinBounds}
 * reads what the container declares about itself and refuses it first.
 *
 * That is a filter, not a sandbox: a parser can still be pathological on input
 * that declares nothing unusual. For documents from people you do not trust, run
 * the conversion through {@see IsolatedConverter}, which executes it in a child
 * process under a kernel-enforced memory cap and wall-clock limit and treats a
 * killed child as a rejected upload. These ceilings then act as the cheap first
 * filter in front of it, which is what they are good at.
 */
final class ConversionLimits
{
    /**
     * @param  int  $maxInputBytes  Decoded size of the document handed to the parser.
     * @param  int  $maxOutputBytes  Size of the produced text or PDF.
     * @param  int  $maxRowsPerSheet  Rows read from any single worksheet.
     * @param  int  $maxCells  Cells read across the whole workbook.
     * @param  float  $maxSeconds  Wall-clock budget for one conversion, measured from
     *                             the moment convert() is entered. Checked only where
     *                             this package holds control — it cannot interrupt a
     *                             parse or a render already running inside PhpSpreadsheet,
     *                             PhpWord or the PDF renderer.
     * @param  int  $maxArchiveEntries  Files a ZIP container (xlsx, docx, ods) may declare.
     * @param  int  $maxUncompressedBytes  What that container may declare it expands to.
     * @param  int  $maxCompressionRatio  How far it may expand. Real Office documents sit
     *                                    in the low tens; a bomb is in the thousands.
     */
    public function __construct(
        public readonly int $maxInputBytes = 33_554_432,      // 32 MB
        public readonly int $maxOutputBytes = 33_554_432,     // 32 MB
        public readonly int $maxRowsPerSheet = 100_000,
        public readonly int $maxCells = 2_000_000,
        public readonly float $maxSeconds = 60.0,
        public readonly int $maxArchiveEntries = 4_096,
        public readonly int $maxUncompressedBytes = 536_870_912, // 512 MB
        public readonly int $maxCompressionRatio = 200,
    ) {}

    /**
     * Refuse a ZIP container whose own central directory says it will cost more
     * than these limits allow.
     *
     * This runs before a parser opens the file, which is the whole point: it is
     * the only check in this class that can stop a decompression bomb, because
     * every other ceiling here is applied to a workbook already in memory.
     *
     * Bounds that cannot be read are not bounds that passed. A container this
     * reader does not understand — ZIP64, damaged, or not a ZIP at all — is
     * left to the caller to decide about, since a CSV is not an archive and
     * refusing it here would be wrong.
     *
     * @throws GenAiFileTooLargeException when the archive declares more than is allowed.
     */
    public function assertArchiveWithinBounds(?ZipBounds $bounds, string $what = 'document'): void
    {
        if ($bounds === null) {
            return;
        }

        if ($bounds->unsafeNames !== []) {
            throw new GenAiFatalException(sprintf(
                'The %s archive names an entry outside its own root (%s), which no Office document does.',
                $what,
                implode(', ', array_slice($bounds->unsafeNames, 0, 3)),
            ));
        }
        if ($bounds->duplicateNames !== []) {
            throw new GenAiFatalException(sprintf(
                'The %s archive records %s more than once; duplicate entries make its contents ambiguous.',
                $what,
                implode(', ', array_slice($bounds->duplicateNames, 0, 3)),
            ));
        }
        if ($bounds->entries > $this->maxArchiveEntries) {
            throw new GenAiFileTooLargeException(
                sprintf(
                    'The %s archive declares %d entries, above the limit of %d.',
                    $what, $bounds->entries, $this->maxArchiveEntries,
                ),
                actualBytes: $bounds->entries,
                limitBytes: $this->maxArchiveEntries,
            );
        }
        if ($bounds->uncompressedBytes > $this->maxUncompressedBytes) {
            throw new GenAiFileTooLargeException(
                sprintf(
                    'The %s archive expands to %s, above the %s limit — it would be opened in memory before any other ceiling applies.',
                    $what,
                    FileLimits::humanBytes($bounds->uncompressedBytes),
                    FileLimits::humanBytes($this->maxUncompressedBytes),
                ),
                actualBytes: $bounds->uncompressedBytes,
                limitBytes: $this->maxUncompressedBytes,
            );
        }
        if ($bounds->compressionRatio() > $this->maxCompressionRatio) {
            throw new GenAiFileTooLargeException(
                sprintf(
                    'The %s archive expands %dx, past the %dx limit. Real Office documents sit far below this.',
                    $what,
                    (int) $bounds->compressionRatio(),
                    $this->maxCompressionRatio,
                ),
                actualBytes: $bounds->uncompressedBytes,
                limitBytes: $this->maxUncompressedBytes,
            );
        }
    }

    /**
     * A copy whose output ceiling is at most $bytes.
     *
     * Only ever tightens: a provider's remaining request budget can make a
     * conversion smaller than the configured ceiling, never larger, so a host
     * that lowered $maxOutputBytes keeps the value it chose.
     */
    public function withMaxOutputBytes(int $bytes): self
    {
        return new self(
            maxInputBytes: $this->maxInputBytes,
            maxOutputBytes: max(0, min($this->maxOutputBytes, $bytes)),
            maxRowsPerSheet: $this->maxRowsPerSheet,
            maxCells: $this->maxCells,
            maxSeconds: $this->maxSeconds,
            maxArchiveEntries: $this->maxArchiveEntries,
            maxUncompressedBytes: $this->maxUncompressedBytes,
            maxCompressionRatio: $this->maxCompressionRatio,
        );
    }

    /**
     * Build limits from `config('genai.conversion')`, falling back to the
     * defaults above for any key that is absent or null.
     *
     * Clients call this when no explicit policy was injected, so the values are
     * reachable from an application that only ever touches the facade or the
     * factory — not just from a direct SpreadsheetToText::convert() call.
     */
    public static function fromConfig(): self
    {
        $cfg = function_exists('config') ? (array) config('genai.conversion', []) : [];
        $defaults = new self;

        return new self(
            maxInputBytes: (int) ($cfg['max_input_bytes'] ?? $defaults->maxInputBytes),
            maxOutputBytes: (int) ($cfg['max_output_bytes'] ?? $defaults->maxOutputBytes),
            maxRowsPerSheet: (int) ($cfg['max_rows_per_sheet'] ?? $defaults->maxRowsPerSheet),
            maxCells: (int) ($cfg['max_cells'] ?? $defaults->maxCells),
            maxSeconds: (float) ($cfg['max_seconds'] ?? $defaults->maxSeconds),
            maxArchiveEntries: (int) ($cfg['max_archive_entries'] ?? $defaults->maxArchiveEntries),
            maxUncompressedBytes: (int) ($cfg['max_uncompressed_bytes'] ?? $defaults->maxUncompressedBytes),
            maxCompressionRatio: (int) ($cfg['max_compression_ratio'] ?? $defaults->maxCompressionRatio),
        );
    }
}
