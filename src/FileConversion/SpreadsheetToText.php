<?php

namespace Bherila\GenAiLaravel\FileConversion;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileLimits;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Converts spreadsheet-format files (xlsx, xls, ods, csv) into a plain-text
 * representation suitable for providers that do not accept Office formats natively.
 *
 * Anthropic's document block only accepts PDF and text/plain, and Gemini's vision
 * pipeline only meaningfully understands PDF. For everything in between — an XLSX
 * the caller would otherwise have to pre-process — this class extracts cell data
 * into a tab-separated layout the model can read directly.
 *
 * Requires phpoffice/phpspreadsheet. Call isAvailable() first if you want to fall
 * back gracefully when the optional dependency is missing.
 */
final class SpreadsheetToText
{
    /**
     * MIME types this converter can turn into plain text.
     *
     * @return list<string>
     */
    public static function supportedMimeTypes(): array
    {
        return [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel', // xls
            'application/vnd.oasis.opendocument.spreadsheet', // ods
            'text/csv',
        ];
    }

    public static function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::supportedMimeTypes(), true);
    }

    /**
     * Whether phpoffice/phpspreadsheet is available in the current install.
     */
    public static function isAvailable(): bool
    {
        return class_exists(IOFactory::class);
    }

    /**
     * Decode base64 spreadsheet bytes and return a tab-separated text rendering.
     *
     * Multi-sheet workbooks are concatenated with a `=== Sheet: <name> ===` header
     * before each sheet so the model can tell them apart.
     */
    public static function convert(string $base64, string $mimeType, ?ConversionLimits $limits = null): string
    {
        $limits ??= new ConversionLimits;

        if (! self::isAvailable()) {
            throw new GenAiFatalException(
                'SpreadsheetToText requires phpoffice/phpspreadsheet. '
                .'Install it with: composer require phpoffice/phpspreadsheet'
            );
        }

        if (! self::supports($mimeType)) {
            throw new GenAiFatalException(sprintf(
                'SpreadsheetToText cannot convert %s. Supported MIME types: %s.',
                $mimeType === '' ? '(no MIME type)' : $mimeType,
                implode(', ', self::supportedMimeTypes()),
            ));
        }

        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new GenAiFatalException('SpreadsheetToText: input is not valid base64.');
        }

        if (strlen($bytes) > $limits->maxInputBytes) {
            throw new GenAiFileTooLargeException(
                sprintf(
                    'SpreadsheetToText: document is %s, above the %s conversion limit. '
                    .'Raise ConversionLimits::$maxInputBytes if this file is trusted.',
                    FileLimits::humanBytes(strlen($bytes)),
                    FileLimits::humanBytes($limits->maxInputBytes),
                ),
                actualBytes: strlen($bytes),
                limitBytes: $limits->maxInputBytes,
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'genai_xlsx_');
        if ($tmp === false) {
            throw new GenAiFatalException('SpreadsheetToText: failed to allocate temp file for conversion.');
        }

        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new GenAiFatalException('SpreadsheetToText: failed to write temp file.');
            }

            try {
                $reader = IOFactory::createReaderForFile($tmp);
                // Formatting is irrelevant to a text extract and is the bulk of the
                // memory an XLSX costs to load, so never materialise it.
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($tmp);
            } catch (\Throwable $e) {
                throw new GenAiFatalException('SpreadsheetToText: failed to read spreadsheet — '.$e->getMessage(), 0, $e);
            }

            return self::renderSpreadsheet($spreadsheet, $limits);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Renders under the supplied ceilings, appending a truncation marker rather
     * than throwing: a partial extract is still useful to the model, and a
     * silent one would be worse than either.
     */
    private static function renderSpreadsheet(Spreadsheet $spreadsheet, ConversionLimits $limits): string
    {
        $deadline = microtime(true) + $limits->maxSeconds;
        $parts = [];
        $cells = 0;
        $bytes = 0;
        $truncated = null;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            if ($truncated !== null) {
                break;
            }

            $header = '=== Sheet: '.$sheet->getTitle().' ===';
            $parts[] = $header;
            $bytes += strlen($header) + 1;
            $rows = 0;

            // Row iteration streams the used range instead of materialising the
            // whole rectangular grid the way toArray() does.
            foreach ($sheet->getRowIterator() as $row) {
                if (++$rows > $limits->maxRowsPerSheet) {
                    $truncated = sprintf('row limit of %d per sheet', $limits->maxRowsPerSheet);
                    break;
                }
                if (microtime(true) > $deadline) {
                    $truncated = sprintf('time limit of %.0fs', $limits->maxSeconds);
                    break;
                }

                $values = [];
                foreach ($row->getCellIterator() as $cell) {
                    if (++$cells > $limits->maxCells) {
                        $truncated = sprintf('cell limit of %d', $limits->maxCells);
                        break;
                    }
                    $values[] = $cell->getFormattedValue();
                }

                $line = rtrim(implode("\t", $values), "\t");
                $bytes += strlen($line) + 1;
                $parts[] = $line;

                if ($bytes > $limits->maxOutputBytes) {
                    $truncated = sprintf('output limit of %s', FileLimits::humanBytes($limits->maxOutputBytes));
                }
                if ($truncated !== null) {
                    break;
                }
            }

            $parts[] = '';
        }

        if ($truncated !== null) {
            $parts[] = '=== Truncated: extraction stopped at the '.$truncated.' ===';
        }

        return rtrim(implode("\n", $parts), "\n")."\n";
    }
}
