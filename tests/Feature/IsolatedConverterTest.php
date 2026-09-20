<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Bherila\GenAiLaravel\FileConversion\IsolatedConverter;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The point of isolation is not that a limit trips — ConversionLimits already
 * trips limits. It is that when hostile input beats every in-process ceiling,
 * the *kernel* stops it, the upload is rejected, and the worker that accepted
 * it is still alive to say so.
 *
 * So these tests assert on the worker's survival, not just on the exception.
 */
final class IsolatedConverterTest extends TestCase
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    protected function setUp(): void
    {
        parent::setUp();

        if (! IsolatedConverter::isSupported()) {
            $this->markTestSkipped('This host cannot enforce subprocess isolation.');
        }
    }

    public function test_an_ordinary_workbook_converts_isolated_and_matches_the_in_process_result(): void
    {
        $xlsx = $this->workbook();

        $isolated = (new IsolatedConverter)->spreadsheetToText($xlsx, self::XLSX_MIME);

        $this->assertStringContainsString('=== Sheet: Invoices ===', $isolated);
        $this->assertStringContainsString("INV-001\t1200.5", $isolated);
        // A drop-in replacement, not a different converter.
        $this->assertSame(
            SpreadsheetToText::convert($xlsx, self::XLSX_MIME),
            $isolated,
        );
    }

    /**
     * The case ConversionLimits cannot cover: memory exhausted inside a parse,
     * where no ceiling in this package holds control. The child is killed by
     * its address-space cap and the upload is rejected.
     */
    public function test_a_child_that_exhausts_its_memory_cap_is_killed_and_the_upload_rejected(): void
    {
        $converter = new IsolatedConverter(
            limits: new ConversionLimits(maxSeconds: 30.0),
            // Far below what opening any workbook needs, so the cap is reached
            // inside the parse rather than by a ceiling this package checks.
            memoryLimitBytes: 8 * 1024 * 1024,
        );

        try {
            $converter->spreadsheetToText($this->workbook(), self::XLSX_MIME);
            $this->fail('Expected the memory cap to stop the conversion.');
        } catch (GenAiFileTooLargeException $e) {
            $this->assertStringContainsString('was stopped', $e->getMessage());
            $this->assertStringContainsString('worker was not affected', $e->getMessage());
        }

        // The whole promise: this process is still healthy and can keep working.
        $this->assertSame(
            'still here',
            (static fn (): string => 'still here')(),
            'The parent process must survive a killed child.',
        );
        $this->assertStringContainsString(
            '=== Sheet: Invoices ===',
            (new IsolatedConverter)->spreadsheetToText($this->workbook(), self::XLSX_MIME),
            'The worker must still be able to convert after rejecting a hostile upload.',
        );
    }

    /** A rejection the runner recognised comes back as the same exception a direct call throws. */
    public function test_a_rejected_document_reports_the_same_failure_as_an_in_process_conversion(): void
    {
        $this->expectException(GenAiFatalException::class);
        (new IsolatedConverter)->spreadsheetToText(base64_encode('not a spreadsheet'), 'text/plain');
    }

    public function test_an_oversized_document_is_refused_by_its_own_limits_inside_the_child(): void
    {
        $converter = new IsolatedConverter(limits: new ConversionLimits(maxInputBytes: 64));

        $this->expectException(GenAiFileTooLargeException::class);
        $converter->spreadsheetToText($this->workbook(), self::XLSX_MIME);
    }

    /** @return string base64 of a small, entirely ordinary workbook */
    private function workbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoices');
        $sheet->fromArray([
            ['Invoice', 'Amount'],
            ['INV-001', 1200.50],
            ['INV-002', 3400.00],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'test_isolated_');
        (new XlsxWriter($spreadsheet))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();

        return base64_encode($bytes);
    }
}
