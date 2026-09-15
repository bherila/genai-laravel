<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Bherila\GenAiLaravel\FileConversion\WordDocumentToPdf;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * The conversion ceilings only mean something if they reach the code paths an
 * application actually uses. Before clients could be given a policy, nothing
 * outside a direct SpreadsheetToText::convert() call could set one — every
 * conversion a client ran used the hardcoded defaults.
 */
class ConversionLimitsTest extends TestCase
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function setUp(): void
    {
        parent::setUp();

        // No conversion in this file should ever reach a provider: every case
        // must fail or truncate before a request is built.
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    public function test_from_config_reads_the_conversion_block(): void
    {
        config([
            'genai.conversion' => [
                'max_input_bytes' => 1024,
                'max_output_bytes' => 2048,
                'max_rows_per_sheet' => 10,
                'max_cells' => 20,
                'max_seconds' => 1.5,
            ],
        ]);

        $limits = ConversionLimits::fromConfig();

        $this->assertSame(1024, $limits->maxInputBytes);
        $this->assertSame(2048, $limits->maxOutputBytes);
        $this->assertSame(10, $limits->maxRowsPerSheet);
        $this->assertSame(20, $limits->maxCells);
        $this->assertSame(1.5, $limits->maxSeconds);
    }

    public function test_from_config_falls_back_to_the_defaults_key_by_key(): void
    {
        config(['genai.conversion' => ['max_cells' => 7]]);

        $limits = ConversionLimits::fromConfig();
        $defaults = new ConversionLimits;

        $this->assertSame(7, $limits->maxCells);
        $this->assertSame($defaults->maxInputBytes, $limits->maxInputBytes);
        $this->assertSame($defaults->maxSeconds, $limits->maxSeconds);
    }

    /**
     * The class used to ship an untrusted() preset that no client could reach and
     * that promised a safety property the code does not enforce. It is gone, and
     * should not come back without real process isolation behind it.
     */
    public function test_there_is_no_untrusted_preset(): void
    {
        $this->assertFalse(
            method_exists(ConversionLimits::class, 'untrusted'),
            'ConversionLimits must not advertise a preset for untrusted input: these are '
            .'best-effort resource guards, not a sandbox.',
        );
    }

    // ── Reaching the clients ─────────────────────────────────────────────────

    public function test_anthropic_applies_an_injected_policy(): void
    {
        $client = new AnthropicClient(
            apiKey: 'test',
            conversionLimits: new ConversionLimits(maxInputBytes: 128),
        );

        $this->expectException(GenAiFileTooLargeException::class);
        $this->expectExceptionMessageMatches('/above the .* conversion limit/');

        $client->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document($this->makeXlsxBase64(), self::XLSX_MIME)],
        ]]);
    }

    public function test_gemini_applies_an_injected_policy(): void
    {
        $client = new GeminiClient(
            apiKey: 'test',
            conversionLimits: new ConversionLimits(maxInputBytes: 128),
        );

        $this->expectException(GenAiFileTooLargeException::class);

        $client->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document($this->makeXlsxBase64(), self::XLSX_MIME)],
        ]]);
    }

    /**
     * The path that matters most: an application that only ever touches the
     * facade or the factory never constructs a ConversionLimits itself, so the
     * config block has to be what reaches the converter.
     */
    public function test_a_client_built_with_no_policy_uses_the_configured_one(): void
    {
        config(['genai.conversion' => ['max_input_bytes' => 128]]);

        $this->expectException(GenAiFileTooLargeException::class);

        (new AnthropicClient(apiKey: 'test'))->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document($this->makeXlsxBase64(), self::XLSX_MIME)],
        ]]);
    }

    // ── The wall-clock budget ────────────────────────────────────────────────

    /**
     * Word conversion never consulted maxSeconds at all: a document could parse
     * and render for as long as PhpWord and the renderer wanted.
     */
    public function test_word_conversion_aborts_once_its_budget_is_spent(): void
    {
        $this->expectException(GenAiFatalException::class);
        $this->expectExceptionMessageMatches('/exceeded its .*budget/');

        WordDocumentToPdf::convert(
            $this->makeDocxBase64(),
            self::DOCX_MIME,
            new ConversionLimits(maxSeconds: -1.0),
        );
    }

    public function test_spreadsheet_extraction_truncates_when_its_budget_is_spent(): void
    {
        $text = SpreadsheetToText::convert(
            $this->makeXlsxBase64(),
            self::XLSX_MIME,
            new ConversionLimits(maxSeconds: -1.0),
        );

        // Truncation, not an exception: a partial extract is still worth sending.
        $this->assertStringContainsString('=== Truncated:', $text);
        $this->assertStringContainsString('time limit', $text);
    }

    private function makeXlsxBase64(): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Invoice', 'Amount'], ['INV-001', 12.5]]);

        $tmp = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        (new XlsxWriter($spreadsheet))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return base64_encode($bytes);
    }

    private function makeDocxBase64(): string
    {
        $phpWord = new PhpWord;
        $phpWord->addSection()->addText('Hello from PhpWord.');

        $tmp = tempnam(sys_get_temp_dir(), 'test_docx_');
        WordIOFactory::createWriter($phpWord, 'Word2007')->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return base64_encode($bytes);
    }
}
