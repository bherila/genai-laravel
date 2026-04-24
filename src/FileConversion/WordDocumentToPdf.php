<?php

namespace Bherila\GenAiLaravel\FileConversion;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;

/**
 * Converts Word-format documents (doc, docx, odt, rtf) into PDF bytes so they
 * can be sent as provider-native document blocks.
 *
 * Anthropic accepts only PDF and plain text, and Gemini only gets real vision
 * understanding on PDF. Rendering through PhpWord → PDF preserves layout, fonts,
 * and tables — so the model sees the same document the author did, rather than
 * a stripped text extract.
 *
 * Requires phpoffice/phpword *and* a PhpWord-compatible PDF renderer (dompdf,
 * mpdf, or tcpdf). Both are declared as `suggest` entries — isAvailable()
 * returns false when either is missing and the client falls back to the
 * standard upfront error.
 */
final class WordDocumentToPdf
{
    /**
     * MIME types this converter can render to PDF.
     *
     * @return list<string>
     */
    public static function supportedMimeTypes(): array
    {
        return [
            'application/msword', // .doc (PhpWord's MsDoc reader — limited but usable for text)
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            'application/vnd.oasis.opendocument.text', // .odt
            'application/rtf',
            'text/rtf',
        ];
    }

    public static function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::supportedMimeTypes(), true);
    }

    /**
     * True when phpoffice/phpword is installed AND at least one PDF renderer
     * (dompdf / mpdf / tcpdf) can be reached through its class.
     */
    public static function isAvailable(): bool
    {
        return class_exists(IOFactory::class) && self::detectPdfRenderer() !== null;
    }

    /**
     * @return array{0: string, 1: string}|null  [rendererName, rendererClass]
     */
    private static function detectPdfRenderer(): ?array
    {
        $candidates = [
            [Settings::PDF_RENDERER_DOMPDF, \Dompdf\Dompdf::class],
            [Settings::PDF_RENDERER_MPDF, \Mpdf\Mpdf::class],
            [Settings::PDF_RENDERER_TCPDF, \TCPDF::class],
        ];
        foreach ($candidates as $entry) {
            if (class_exists($entry[1])) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Decode base64 Word-doc bytes and return a base64-encoded PDF rendering.
     *
     * Returned value is ready to drop into a `document` content block with
     * mime_type `application/pdf`.
     */
    public static function convert(string $base64, string $mimeType): string
    {
        if (! class_exists(IOFactory::class)) {
            throw new GenAiFatalException(
                'WordDocumentToPdf requires phpoffice/phpword. Install with: '
                .'composer require phpoffice/phpword'
            );
        }

        $renderer = self::detectPdfRenderer();
        if ($renderer === null) {
            throw new GenAiFatalException(
                'WordDocumentToPdf requires a PhpWord-compatible PDF renderer. Install one of: '
                .'dompdf/dompdf, mpdf/mpdf, tecnickcom/tcpdf.'
            );
        }

        if (! self::supports($mimeType)) {
            throw new GenAiFatalException(sprintf(
                'WordDocumentToPdf cannot convert %s. Supported MIME types: %s.',
                $mimeType === '' ? '(no MIME type)' : $mimeType,
                implode(', ', self::supportedMimeTypes()),
            ));
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new GenAiFatalException('WordDocumentToPdf: input is not valid base64.');
        }

        $readerName = self::readerNameForMime($mimeType);
        $inputTmp = tempnam(sys_get_temp_dir(), 'genai_word_');
        $outputTmp = tempnam(sys_get_temp_dir(), 'genai_pdf_');
        if ($inputTmp === false || $outputTmp === false) {
            if ($inputTmp !== false) {
                @unlink($inputTmp);
            }
            if ($outputTmp !== false) {
                @unlink($outputTmp);
            }
            throw new GenAiFatalException('WordDocumentToPdf: failed to allocate temp files.');
        }

        try {
            file_put_contents($inputTmp, $bytes);

            Settings::setPdfRendererName($renderer[0]);
            // PhpWord requires a real readable directory here. It's only used to
            // extend the include_path — composer's autoloader still resolves the
            // actual classes — so pointing it at the renderer's own source dir
            // satisfies the check without interfering with autoloading.
            $reflector = new \ReflectionClass($renderer[1]);
            $rendererDir = dirname((string) $reflector->getFileName());
            Settings::setPdfRendererPath($rendererDir);

            try {
                $phpWord = IOFactory::load($inputTmp, $readerName);
            } catch (\Throwable $e) {
                throw new GenAiFatalException(
                    'WordDocumentToPdf: failed to read document — '.$e->getMessage(),
                    0,
                    $e,
                );
            }

            try {
                $writer = IOFactory::createWriter($phpWord, 'PDF');
                $writer->save($outputTmp);
            } catch (\Throwable $e) {
                throw new GenAiFatalException(
                    'WordDocumentToPdf: failed to render PDF — '.$e->getMessage(),
                    0,
                    $e,
                );
            }

            $pdfBytes = file_get_contents($outputTmp);
            if ($pdfBytes === false || $pdfBytes === '') {
                throw new GenAiFatalException('WordDocumentToPdf: renderer produced an empty PDF.');
            }

            return base64_encode($pdfBytes);
        } finally {
            @unlink($inputTmp);
            @unlink($outputTmp);
        }
    }

    private static function readerNameForMime(string $mimeType): string
    {
        return match ($mimeType) {
            'application/msword' => 'MsDoc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word2007',
            'application/vnd.oasis.opendocument.text' => 'ODText',
            'application/rtf', 'text/rtf' => 'RTF',
            default => 'Word2007',
        };
    }
}
