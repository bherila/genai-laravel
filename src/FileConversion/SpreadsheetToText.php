<?php

namespace Bherila\GenAiLaravel\FileConversion;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
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
    public static function convert(string $base64, string $mimeType): string
    {
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

        $tmp = tempnam(sys_get_temp_dir(), 'genai_xlsx_');
        if ($tmp === false) {
            throw new GenAiFatalException('SpreadsheetToText: failed to allocate temp file for conversion.');
        }

        try {
            file_put_contents($tmp, $bytes);

            try {
                $spreadsheet = IOFactory::load($tmp);
            } catch (\Throwable $e) {
                throw new GenAiFatalException('SpreadsheetToText: failed to read spreadsheet — '.$e->getMessage(), 0, $e);
            }

            return self::renderSpreadsheet($spreadsheet);
        } finally {
            @unlink($tmp);
        }
    }

    private static function renderSpreadsheet(Spreadsheet $spreadsheet): string
    {
        $parts = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $parts[] = '=== Sheet: '.$sheet->getTitle().' ===';

            // toArray returns a rectangular grid covering the used range.
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_map(
                    fn ($v) => $v === null ? '' : (string) $v,
                    $row,
                );
                $parts[] = implode("\t", $cells);
            }
            $parts[] = '';
        }

        return rtrim(implode("\n", $parts), "\n")."\n";
    }
}
