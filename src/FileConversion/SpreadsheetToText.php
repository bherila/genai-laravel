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
     * before each sheet so the model can tell them apart. A workbook past a
     * ceiling ends with a `=== Truncated: … ===` line; use
     * {@see convertWithReport()} to learn that without reading the text.
     */
    public static function convert(string $base64, string $mimeType, ?ConversionLimits $limits = null): string
    {
        return self::convertWithReport($base64, $mimeType, $limits)->text;
    }

    /**
     * The same rendering as {@see convert()}, with whether it holds the whole
     * workbook reported as data.
     *
     * `$truncation` is null only when no ceiling cut the extract short. That
     * includes the cut a reader makes while loading: a cell past the row
     * ceiling is never built, so a sparse sheet — an empty span of rows, then
     * one value past the ceiling — renders a few rows and reaches no limit
     * while the value is lost. When the read filter refused anything, the
     * file is read a second time for values alone
     * ({@see UnreadSpreadsheetValues}), and a value found there, or any doubt,
     * is reported as the row limit. That reading runs on the conversion's
     * clock, and is reported as the time limit if the clock runs out first.
     */
    public static function convertWithReport(string $base64, string $mimeType, ?ConversionLimits $limits = null): SpreadsheetConversion
    {
        $limits ??= ConversionLimits::fromConfig();
        // The clock starts here, not after load(): parsing the workbook is the
        // slowest part of a conversion, so a deadline that began afterwards
        // would exclude the very work it is meant to bound.
        $deadline = microtime(true) + $limits->maxSeconds;

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
                    .'Raise ConversionLimits::$maxInputBytes to accept documents this large.',
                    FileLimits::humanBytes(strlen($bytes)),
                    FileLimits::humanBytes($limits->maxInputBytes),
                ),
                actualBytes: strlen($bytes),
                limitBytes: $limits->maxInputBytes,
            );
        }

        // The archive's own central directory says what it will cost to open,
        // and reading it is the only check that can happen before a parser
        // materialises the contents. A decompression bomb is refused here or
        // not at all.
        $limits->assertArchiveWithinBounds(ZipBounds::read($bytes), 'spreadsheet');

        $tmp = tempnam(sys_get_temp_dir(), 'genai_xlsx_');
        if ($tmp === false) {
            throw new GenAiFatalException('SpreadsheetToText: failed to allocate temp file for conversion.');
        }

        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new GenAiFatalException('SpreadsheetToText: failed to write temp file.');
            }

            try {
                $format = IOFactory::identify($tmp);
                $reader = IOFactory::createReader($format);
                // Number formats are what make a spreadsheet readable: they turn a
                // serial into a date, a fraction into a percentage and a bare number
                // into a currency amount. They live in the workbook's style records,
                // which setReadDataOnly(true) skips entirely — so getFormattedValue()
                // below used to hand the model `45672` for a cell the sheet plainly
                // shows as `2025-01-15`, with nothing to signal the difference.
                //
                // PhpSpreadsheet has no "formats but not fonts" lever, so the styles
                // load as a unit. They cost much less than the old comment here
                // claimed: a workbook dedupes its style records, so the price is set
                // by how many distinct formats it uses, not by how many cells it has.
                // A 40,000-cell workbook with eight formats measured no extra peak
                // memory and about a third more parse time. The ceilings in
                // ConversionLimits still bound both, and they remain what they always
                // were — a guard against runaway honest documents, not a sandbox.
                $reader->setReadDataOnly(false);
                // Charts, drawings and external images are already off by default;
                // none of them can reach a text extract, and the last would make a
                // conversion fetch a URL the document chose.
                $reader->setIncludeCharts(false);
                $reader->setAllowExternalImages(false);
                // Applied at load() so the ceilings bound what is read. Without
                // it they bound only what is rendered, and a sheet with one
                // value in its far corner is fully built before anything counts.
                $filter = BoundedReadFilter::for($limits);
                $reader->setReadFilter($filter);
                $spreadsheet = $reader->load($tmp);
            } catch (\Throwable $e) {
                throw new GenAiFatalException('SpreadsheetToText: failed to read spreadsheet — '.$e->getMessage(), 0, $e);
            }

            [$parts, $truncation] = self::renderSpreadsheet($spreadsheet, $limits, $deadline);
            $sheetCount = $spreadsheet->getSheetCount();
            // Released before the second reading, which then never shares
            // the process with the loaded workbook.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            if ($truncation === null && $filter->refusedAny()) {
                $truncation = microtime(true) > $deadline
                    ? SpreadsheetTruncation::TimeLimit
                    : UnreadSpreadsheetValues::find($tmp, $format, $reader, $filter, $sheetCount, $deadline);
            }

            if ($truncation !== null) {
                $parts[] = '=== Truncated: extraction stopped at the '.$truncation->describe($limits).' ===';
            }

            return new SpreadsheetConversion(rtrim(implode("\n", $parts), "\n")."\n", $truncation);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Renders under the supplied ceilings, stopping at the first one reached
     * rather than throwing: a partial extract is still useful to the model,
     * and a silent one would be worse than either. The caller writes the
     * marker, so a cut found after rendering is reported the same way.
     *
     * @param  float  $deadline  microtime(true) value past which extraction stops.
     * @return array{0: list<string>, 1: SpreadsheetTruncation|null}
     */
    private static function renderSpreadsheet(Spreadsheet $spreadsheet, ConversionLimits $limits, float $deadline): array
    {
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
                    $truncated = SpreadsheetTruncation::RowLimit;
                    break;
                }
                if (microtime(true) > $deadline) {
                    $truncated = SpreadsheetTruncation::TimeLimit;
                    break;
                }

                $values = [];
                foreach ($row->getCellIterator() as $cell) {
                    if (++$cells > $limits->maxCells) {
                        $truncated = SpreadsheetTruncation::CellLimit;
                        break;
                    }
                    $values[] = $cell->getFormattedValue();
                }

                $line = rtrim(implode("\t", $values), "\t");
                $bytes += strlen($line) + 1;
                $parts[] = $line;

                if ($bytes > $limits->maxOutputBytes) {
                    $truncated = SpreadsheetTruncation::OutputLimit;
                }
                if ($truncated !== null) {
                    break;
                }
            }

            $parts[] = '';
        }

        return [$parts, $truncated];
    }
}
