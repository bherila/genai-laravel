<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class SpreadsheetToTextTest extends TestCase
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Built once: every budget test needs the same oversized workbook. */
    private static ?string $hugeXlsx = null;

    /** Build a small two-sheet workbook and return it as base64. */
    private function makeXlsxBase64(): string
    {
        $spreadsheet = new Spreadsheet;

        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Invoices');
        $sheet1->fromArray([
            ['Invoice', 'Amount', 'Due'],
            ['INV-001', 1200.50, '2026-05-01'],
            ['INV-002', 3400.00, '2026-05-08'],
        ]);

        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Customers');
        $sheet2->fromArray([
            ['Name', 'City'],
            ['Acme', 'Boston'],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        (new XlsxWriter($spreadsheet))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return base64_encode($bytes);
    }

    /**
     * A workbook whose numeric cells carry native display formats. Each value is
     * stored as the bare number Excel keeps on disk; only the number format says
     * what it means, which is exactly the information a data-only read discards.
     */
    private function makeFormattedXlsxBase64(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Formats');

        $rows = [
            // [label, stored value, format code]
            ['date', Date::PHPToExcel(new \DateTimeImmutable('2025-01-15')), 'yyyy-mm-dd'],
            ['time', 0.5, 'hh:mm'],
            ['percent', 0.1234, '0.00%'],
            ['currency', 1234.5, '"$"#,##0.00'],
            ['plain', 42, NumberFormat::FORMAT_GENERAL],
        ];
        foreach ($rows as $index => [$label, $value, $format]) {
            $line = $index + 1;
            $sheet->setCellValue('A'.$line, $label);
            $sheet->setCellValue('B'.$line, $value);
            $sheet->getStyle('B'.$line)->getNumberFormat()->setFormatCode($format);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'test_fmt_xlsx_');
        (new XlsxWriter($spreadsheet))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return base64_encode($bytes);
    }

    // ── Converter ────────────────────────────────────────────────────────────

    public function test_converts_xlsx_to_tab_separated_text(): void
    {
        $text = SpreadsheetToText::convert($this->makeXlsxBase64(), self::XLSX_MIME);

        $this->assertStringContainsString('=== Sheet: Invoices ===', $text);
        $this->assertStringContainsString("Invoice\tAmount\tDue", $text);
        $this->assertStringContainsString("INV-001\t1200.5\t2026-05-01", $text);
        $this->assertStringContainsString('=== Sheet: Customers ===', $text);
        $this->assertStringContainsString("Acme\tBoston", $text);
    }

    public function test_converts_csv_to_tab_separated_text(): void
    {
        $csv = "Name,City\nAcme,Boston\nWidgets,Portland\n";
        $text = SpreadsheetToText::convert(base64_encode($csv), 'text/csv');

        $this->assertStringContainsString('Name', $text);
        $this->assertStringContainsString('Acme', $text);
        $this->assertStringContainsString('Boston', $text);
        $this->assertStringContainsString('Widgets', $text);
    }

    public function test_rejects_unsupported_mime_type(): void
    {
        $this->expectException(GenAiFatalException::class);
        SpreadsheetToText::convert(base64_encode('x'), 'application/pdf');
    }

    public function test_rejects_invalid_base64(): void
    {
        $this->expectException(GenAiFatalException::class);
        SpreadsheetToText::convert('not!!valid!!base64!!', self::XLSX_MIME);
    }

    public function test_supported_list_includes_xlsx_xls_ods_csv(): void
    {
        $types = SpreadsheetToText::supportedMimeTypes();
        $this->assertContains(self::XLSX_MIME, $types);
        $this->assertContains('application/vnd.ms-excel', $types);
        $this->assertContains('application/vnd.oasis.opendocument.spreadsheet', $types);
        $this->assertContains('text/csv', $types);
    }

    // ── Anthropic integration ────────────────────────────────────────────────

    public function test_anthropic_auto_converts_xlsx_to_text_block(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $xlsx = $this->makeXlsxBase64();
        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');
        $client->converseWithInlineFile($xlsx, self::XLSX_MIME, 'Summarize the invoices.');

        Http::assertSent(function (Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];

            // Instead of a document block, the xlsx was converted to a text block.
            $hasDocumentBlock = false;
            $extractedText = '';
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'document') {
                    $hasDocumentBlock = true;
                }
                if (($block['type'] ?? '') === 'text') {
                    $extractedText .= $block['text'] ?? '';
                }
            }

            return ! $hasDocumentBlock
                && str_contains($extractedText, 'Invoice')
                && str_contains($extractedText, 'INV-001')
                && str_contains($extractedText, 'Summarize the invoices.');
        });
    }

    public function test_anthropic_auto_converts_xlsx_via_content_block(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');
        $client->converse('', [[
            'role' => 'user',
            'content' => [
                ContentBlock::document($this->makeXlsxBase64(), self::XLSX_MIME),
                ContentBlock::text('What do you see?'),
            ],
        ]]);

        Http::assertSent(function (Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text' && str_contains($block['text'] ?? '', 'INV-001')) {
                    return true;
                }
            }

            return false;
        });
    }

    // ── Gemini integration ───────────────────────────────────────────────────

    public function test_gemini_auto_converts_xlsx_to_text_part(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
        ])]);

        $xlsx = $this->makeXlsxBase64();
        (new GeminiClient(apiKey: 'test'))
            ->converseWithInlineFile($xlsx, self::XLSX_MIME, 'Summarize.');

        Http::assertSent(function (Request $req) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];

            $hasInlineData = false;
            $extractedText = '';
            foreach ($parts as $part) {
                if (isset($part['inline_data'])) {
                    $hasInlineData = true;
                }
                if (isset($part['text'])) {
                    $extractedText .= $part['text'];
                }
            }

            return ! $hasInlineData
                && str_contains($extractedText, 'INV-001')
                && str_contains($extractedText, 'Summarize.');
        });
    }

    // ── display formats survive extraction ───────────────────────────────────

    /**
     * A spreadsheet stores `2025-01-15` as the number 45672 and `12.34%` as
     * 0.1234; the number format is the only thing that says which is which.
     * Reading data only discarded it, so the model was handed the serials with
     * nothing to mark them as dates — a silent loss of meaning, not a
     * formatting nicety.
     */
    public function test_native_numeric_formats_are_extracted_as_displayed_values(): void
    {
        $text = SpreadsheetToText::convert($this->makeFormattedXlsxBase64(), self::XLSX_MIME);

        $this->assertStringContainsString("date\t2025-01-15", $text);
        $this->assertStringContainsString("time\t12:00", $text);
        $this->assertStringContainsString("percent\t12.34%", $text);
        $this->assertStringContainsString('currency'."\t".'$1,234.50', $text);
    }

    public function test_unformatted_values_are_unchanged(): void
    {
        $text = SpreadsheetToText::convert($this->makeFormattedXlsxBase64(), self::XLSX_MIME);

        $this->assertStringContainsString("plain\t42", $text);
        $this->assertStringNotContainsString('45672', $text);
        $this->assertStringNotContainsString('0.1234', $text);
    }

    public function test_anthropic_sends_displayed_values_not_stored_serials(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        (new AnthropicClient(apiKey: 'k'))->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document($this->makeFormattedXlsxBase64(), self::XLSX_MIME, 'book.xlsx')],
        ]]);

        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return str_contains($body, '2025-01-15')
                && str_contains($body, '12.34%')
                && ! str_contains($body, '45672');
        });
    }

    public function test_gemini_sends_displayed_values_not_stored_serials(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []])]);

        (new GeminiClient(apiKey: 'k', model: 'gemini-3.6-flash'))->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document($this->makeFormattedXlsxBase64(), self::XLSX_MIME, 'book.xlsx')],
        ]]);

        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return str_contains($body, '2025-01-15')
                && str_contains($body, '12.34%')
                && ! str_contains($body, '45672');
        });
    }

    // ── extraction is bounded by the provider request budget ─────────────────

    /**
     * A workbook whose extract is far larger than any provider request budget.
     *
     * Few, very wide cells rather than many small ones: the extract still has
     * to clear 20 MB, but building it costs a fraction of the cell objects —
     * and it is built once for the whole class, because every test here needs
     * the same oversized input.
     */
    private function makeHugeXlsxBase64(): string
    {
        if (self::$hugeXlsx !== null) {
            return self::$hugeXlsx;
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Big');
        $cell = str_repeat('x', 2_000);
        for ($row = 1; $row <= 600; $row++) {
            for ($column = 1; $column <= 25; $column++) {
                $sheet->setCellValue([$column, $row], $cell);
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'test_big_xlsx_');
        (new XlsxWriter($spreadsheet))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();

        return self::$hugeXlsx = base64_encode((string) $bytes);
    }

    /**
     * The standalone conversion ceiling is as large as — on Gemini, larger
     * than — the whole request budget, so an unbounded extract fills the
     * request by itself and the aggregate check throws it away after the work
     * is already paid for. The extract has to know its allowance instead.
     */
    public function test_gemini_sends_a_truncated_extract_rather_than_failing_the_request(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []])]);

        (new GeminiClient(apiKey: 'k', model: 'gemini-3.6-flash'))->converse('', [[
            'role' => 'user',
            'content' => [
                ContentBlock::document($this->makeHugeXlsxBase64(), self::XLSX_MIME, 'big.xlsx'),
                ContentBlock::text('Summarise this.'),
            ],
        ]]);

        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return strlen($body) <= GeminiClient::maxRequestBytes()
                // Still a useful extract: the sheet header, real cell data and
                // an explicit marker saying where it stopped.
                && str_contains($body, '=== Sheet: Big ===')
                && str_contains($body, str_repeat('x', 100))
                && str_contains($body, 'Truncated: extraction stopped')
                && str_contains($body, 'Summarise this.');
        });
    }

    public function test_anthropic_sends_a_truncated_extract_rather_than_failing_the_request(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        (new AnthropicClient(apiKey: 'k'))->converse('', [[
            'role' => 'user',
            'content' => [
                ContentBlock::document($this->makeHugeXlsxBase64(), self::XLSX_MIME, 'big.xlsx'),
                ContentBlock::text('Summarise this.'),
            ],
        ]]);

        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data(), JSON_THROW_ON_ERROR);

            return strlen($body) <= AnthropicClient::maxRequestBytes()
                && str_contains($body, '=== Sheet: Big ===')
                && str_contains($body, 'Truncated: extraction stopped');
        });
    }
}
