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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

class SpreadsheetToTextTest extends TestCase
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Build a small two-sheet workbook and return it as base64. */
    private function makeXlsxBase64(): string
    {
        $spreadsheet = new Spreadsheet();

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
}
