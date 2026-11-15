<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\FileConversion\BoundedReadFilter;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetConversion;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetTruncation;
use Bherila\GenAiLaravel\FileConversion\UnreadSpreadsheetValues;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whether an extract is the whole workbook, reported as data.
 *
 * The read filter drops every cell past the row ceiling while the workbook
 * loads, and the renderer only sees what was loaded. A sparse sheet — a value
 * on row 1, then nothing until a value far past the ceiling — therefore
 * rendered one row, reached no limit, and came back looking complete while
 * the far value was never read. A caller that must see everything, such as a
 * scan for personal data, had only the marker line to go on, and the marker
 * was missing in exactly that case.
 */
final class SpreadsheetConversionReportTest extends TestCase
{
    private const MIMES = [
        'Xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'Xls' => 'application/vnd.ms-excel',
        'Csv' => 'text/csv',
    ];

    private const ODS_MIME = 'application/vnd.oasis.opendocument.spreadsheet';

    /** Small enough that a fixture can cross it with a handful of cells. */
    private function limits(): ConversionLimits
    {
        return new ConversionLimits(maxRowsPerSheet: 10);
    }

    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return array_combine(array_keys(self::MIMES), array_map(fn (string $format): array => [$format], array_keys(self::MIMES)));
    }

    #[DataProvider('formats')]
    public function test_a_value_the_read_filter_dropped_is_reported_as_the_row_limit(string $format): void
    {
        $workbook = $this->workbook($format, function (Spreadsheet $spreadsheet): void {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'Client');
            $spreadsheet->getActiveSheet()->setCellValue('B100', '123-45-6789');
        });

        $conversion = SpreadsheetToText::convertWithReport($workbook, self::MIMES[$format], $this->limits());

        // The value itself was not read — that is the ceiling doing its job —
        // and nothing about the rendered rows reached a limit.
        $this->assertStringContainsString('Client', $conversion->text);
        $this->assertStringNotContainsString('123-45-6789', $conversion->text);
        $this->assertSame(SpreadsheetTruncation::RowLimit, $conversion->truncation);
        $this->assertFalse($conversion->isComplete());
        // The model is told as well, the same way as for any other cut.
        $this->assertStringContainsString('=== Truncated: extraction stopped at the row limit of 10 per sheet ===', $conversion->text);
        $this->assertSame($conversion->text, SpreadsheetToText::convert($workbook, self::MIMES[$format], $this->limits()));
    }

    #[DataProvider('formats')]
    public function test_a_workbook_within_the_ceiling_is_complete(string $format): void
    {
        $workbook = $this->workbook($format, function (Spreadsheet $spreadsheet): void {
            $spreadsheet->getActiveSheet()->fromArray([['Client', 'Balance'], ['Ada', 12], ['Grace', 40]]);
            $spreadsheet->getActiveSheet()->setCellValue('A10', 'last row in reach');
        });

        $conversion = SpreadsheetToText::convertWithReport($workbook, self::MIMES[$format], $this->limits());

        $this->assertNull($conversion->truncation);
        $this->assertTrue($conversion->isComplete());
        $this->assertStringContainsString('last row in reach', $conversion->text);
        $this->assertStringNotContainsString('Truncated', $conversion->text);
    }

    /**
     * Readers consult the filter for formatting-only cells too, so a refusal
     * alone is not a lost value. Excel and OpenDocument are read a second
     * time for values, and a sheet formatted past the ceiling stays complete.
     */
    public function test_formatting_past_the_ceiling_is_not_a_lost_value(): void
    {
        foreach (['Xlsx', 'Ods'] as $format) {
            $workbook = $this->workbook($format, function (Spreadsheet $spreadsheet): void {
                $spreadsheet->getActiveSheet()->setCellValue('A1', 'Client');
                $spreadsheet->getActiveSheet()->getStyle('A20:C500')->getFont()->setBold(true);
            });

            $conversion = SpreadsheetToText::convertWithReport($workbook, self::MIMES[$format], $this->limits());

            $this->assertNull($conversion->truncation, $format);
            $this->assertStringContainsString('Client', $conversion->text, $format);
        }
    }

    /**
     * A legacy `.xls` is not read a second time, so formatting past the
     * ceiling is reported as a cut. That over-reports; it never under-reports.
     */
    public function test_an_xls_formatted_past_the_ceiling_is_reported_as_cut(): void
    {
        $workbook = $this->workbook('Xls', function (Spreadsheet $spreadsheet): void {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'Client');
            $spreadsheet->getActiveSheet()->getStyle('B100')->getFont()->setBold(true);
        });

        $this->assertSame(
            SpreadsheetTruncation::RowLimit,
            SpreadsheetToText::convertWithReport($workbook, self::MIMES['Xls'], $this->limits())->truncation,
        );
    }

    /**
     * The form the defect was reported in: an OpenDocument sheet whose empty
     * rows are one repeated span, then a value past the ceiling.
     */
    public function test_a_repeated_empty_row_span_does_not_hide_the_value_after_it(): void
    {
        $workbook = $this->odsWithRows(
            '<table:table-row><table:table-cell office:value-type="string"><text:p>Client</text:p></table:table-cell></table:table-row>'
            .'<table:table-row table:number-rows-repeated="100000"><table:table-cell/></table:table-row>'
            .'<table:table-row><table:table-cell office:value-type="string"><text:p>123-45-6789</text:p></table:table-cell></table:table-row>',
        );

        $conversion = SpreadsheetToText::convertWithReport($workbook, self::ODS_MIME, $this->limits());

        $this->assertStringContainsString('Client', $conversion->text);
        $this->assertStringNotContainsString('123-45-6789', $conversion->text);
        $this->assertSame(SpreadsheetTruncation::RowLimit, $conversion->truncation);
    }

    /**
     * OpenDocument exports end a sheet with an empty, self-closing repeated
     * row. `Ods::listWorksheetInfo()` never returns from one, so the second
     * reading streams the file instead and finishes; here it must also find
     * nothing, since the cells past the ceiling are styled but empty.
     */
    public function test_an_empty_trailing_repeated_row_is_read_to_the_end_and_counts_for_nothing(): void
    {
        $workbook = $this->odsWithRows(
            '<table:table-row><table:table-cell office:value-type="string"><text:p>Client</text:p></table:table-cell></table:table-row>'
            .'<table:table-row table:number-rows-repeated="50"><table:table-cell table:number-columns-repeated="3"/></table:table-row>'
            .'<table:table-row table:number-rows-repeated="1048000"/>',
        );

        $conversion = SpreadsheetToText::convertWithReport($workbook, self::ODS_MIME, $this->limits());

        $this->assertNull($conversion->truncation);
        $this->assertStringContainsString('Client', $conversion->text);
    }

    /**
     * The ODS reader reads a formula, and may read a typed value, from a
     * cell's attributes alone, so a self-closing cell past the ceiling is a
     * value lost at load just as one with a paragraph is.
     */
    public function test_a_self_closing_ods_formula_or_typed_cell_past_the_ceiling_is_a_lost_value(): void
    {
        foreach ([
            '<table:table-cell table:formula="of:=123456789"/>',
            '<table:table-cell office:value-type="float" office:value="123456789"/>',
        ] as $cell) {
            $workbook = $this->odsWithRows(
                '<table:table-row><table:table-cell office:value-type="string"><text:p>Client</text:p></table:table-cell></table:table-row>'
                .'<table:table-row table:number-rows-repeated="500"><table:table-cell/></table:table-row>'
                .'<table:table-row>'.$cell.'</table:table-row>',
            );

            $this->assertSame(
                SpreadsheetTruncation::RowLimit,
                SpreadsheetToText::convertWithReport($workbook, self::ODS_MIME, $this->limits())->truncation,
                $cell,
            );
        }
    }

    /**
     * The second reading runs on the conversion's clock. Once the deadline
     * has passed it stops and reports the time limit rather than running on,
     * or answering as though it had read the whole file.
     */
    public function test_the_second_reading_stops_at_the_conversion_deadline(): void
    {
        $formattedPastTheCeiling = function (Spreadsheet $spreadsheet): void {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'Client');
            $spreadsheet->getActiveSheet()->getStyle('A20:C500')->getFont()->setBold(true);
        };

        foreach (['Xlsx', 'Ods'] as $format) {
            $path = tempnam(sys_get_temp_dir(), 'test_deadline_');
            file_put_contents($path, base64_decode($this->workbook($format, $formattedPastTheCeiling)));
            $reader = IOFactory::createReader($format);
            $filter = BoundedReadFilter::for($this->limits());

            try {
                // With time left the reading finds nothing lost; with none it
                // reports the time limit instead of the same answer.
                $this->assertNull(UnreadSpreadsheetValues::find($path, $format, $reader, $filter, 1, microtime(true) + 60), $format);
                $this->assertSame(
                    SpreadsheetTruncation::TimeLimit,
                    UnreadSpreadsheetValues::find($path, $format, $reader, $filter, 1, microtime(true) - 1),
                    $format,
                );
            } finally {
                @unlink($path);
            }
        }
    }

    /** A cut the renderer reaches is reported the same way, with its own reason. */
    public function test_a_ceiling_the_renderer_reaches_is_reported_too(): void
    {
        $rows = $this->workbook('Xlsx', function (Spreadsheet $spreadsheet): void {
            for ($row = 1; $row <= 30; $row++) {
                $spreadsheet->getActiveSheet()->setCellValue("A{$row}", "row {$row}");
            }
        });

        $this->assertSame(
            SpreadsheetTruncation::RowLimit,
            SpreadsheetToText::convertWithReport($rows, self::MIMES['Xlsx'], $this->limits())->truncation,
        );
        $this->assertSame(
            SpreadsheetTruncation::CellLimit,
            SpreadsheetToText::convertWithReport($rows, self::MIMES['Xlsx'], new ConversionLimits(maxCells: 5))->truncation,
        );
        $this->assertSame(
            SpreadsheetTruncation::OutputLimit,
            SpreadsheetToText::convertWithReport($rows, self::MIMES['Xlsx'], new ConversionLimits(maxOutputBytes: 40))->truncation,
        );
    }

    public function test_a_conversion_survives_the_trip_through_an_array(): void
    {
        $cut = new SpreadsheetConversion("a\n", SpreadsheetTruncation::RowLimit);
        $whole = new SpreadsheetConversion("a\n");

        $this->assertEquals($cut, SpreadsheetConversion::fromArray($cut->toArray()));
        $this->assertEquals($whole, SpreadsheetConversion::fromArray($whole->toArray()));
        $this->assertTrue($whole->isComplete());
    }

    public function test_an_unknown_truncation_is_refused_rather_than_read_as_complete(): void
    {
        $this->expectException(\ValueError::class);

        SpreadsheetConversion::fromArray(['text' => "a\n", 'truncation' => 'somewhere']);
    }

    /** @param  \Closure(Spreadsheet): void  $fill */
    private function workbook(string $format, \Closure $fill): string
    {
        $spreadsheet = new Spreadsheet;
        $fill($spreadsheet);

        $path = tempnam(sys_get_temp_dir(), 'test_report_');
        try {
            IOFactory::createWriter($spreadsheet, $format)->save($path);

            return base64_encode((string) file_get_contents($path));
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * An OpenDocument workbook with exactly these rows in its one sheet.
     *
     * Starts from a file the writer produced, so everything around the sheet
     * is what the reader expects, and replaces the sheet's rows.
     */
    private function odsWithRows(string $rows): string
    {
        $base = base64_decode($this->workbook('Ods', fn (Spreadsheet $spreadsheet) => null));
        $path = tempnam(sys_get_temp_dir(), 'test_ods_');
        file_put_contents($path, $base);

        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $content = (string) $zip->getFromName('content.xml');
            $replaced = preg_replace('#(<table:table [^>]*>)(.*?)(</table:table>)#s', '$1<table:table-column/>'.$rows.'$3', $content, 1, $count);
            $this->assertSame(1, $count);
            $zip->addFromString('content.xml', (string) $replaced);
            $zip->close();

            return base64_encode((string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
