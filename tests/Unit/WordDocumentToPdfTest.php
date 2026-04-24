<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\FileConversion\WordDocumentToPdf;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

class WordDocumentToPdfTest extends TestCase
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** Build a tiny single-paragraph DOCX and return it as base64. */
    private function makeDocxBase64(string $paragraph = 'Hello from PhpWord.'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText($paragraph);

        $tmp = tempnam(sys_get_temp_dir(), 'test_docx_');
        WordIOFactory::createWriter($phpWord, 'Word2007')->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return base64_encode($bytes);
    }

    // ── Converter ────────────────────────────────────────────────────────────

    public function test_is_available_when_phpword_and_dompdf_installed(): void
    {
        $this->assertTrue(WordDocumentToPdf::isAvailable(), 'phpword + dompdf should both be in require-dev');
    }

    public function test_supported_mime_types(): void
    {
        $types = WordDocumentToPdf::supportedMimeTypes();
        $this->assertContains(self::DOCX_MIME, $types);
        $this->assertContains('application/msword', $types);
        $this->assertContains('application/vnd.oasis.opendocument.text', $types);
        $this->assertContains('application/rtf', $types);
    }

    public function test_converts_docx_to_pdf_base64(): void
    {
        $docxB64 = $this->makeDocxBase64();
        $pdfB64 = WordDocumentToPdf::convert($docxB64, self::DOCX_MIME);

        $raw = base64_decode($pdfB64, true);
        $this->assertNotFalse($raw, 'output must be valid base64');
        $this->assertStringStartsWith('%PDF-', $raw, 'rendered output must be a real PDF');
    }

    public function test_rejects_unsupported_mime(): void
    {
        $this->expectException(GenAiFatalException::class);
        WordDocumentToPdf::convert(base64_encode('x'), 'application/pdf');
    }

    public function test_rejects_invalid_base64(): void
    {
        $this->expectException(GenAiFatalException::class);
        WordDocumentToPdf::convert('!!!not base64!!!', self::DOCX_MIME);
    }

    // ── Anthropic integration ────────────────────────────────────────────────

    public function test_anthropic_auto_converts_docx_to_pdf_document_block(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        (new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6'))
            ->converseWithInlineFile($this->makeDocxBase64('Quarterly report'), self::DOCX_MIME, 'Summarize.');

        Http::assertSent(function (Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'document'
                    && ($block['source']['media_type'] ?? '') === 'application/pdf'
                    && str_starts_with(
                        (string) base64_decode($block['source']['data'] ?? '', true),
                        '%PDF-',
                    )
                ) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_anthropic_auto_converts_docx_via_content_block(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        (new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6'))
            ->converse('', [[
                'role' => 'user',
                'content' => [
                    ContentBlock::document($this->makeDocxBase64(), self::DOCX_MIME),
                    ContentBlock::text('What does it say?'),
                ],
            ]]);

        Http::assertSent(function (Request $req) {
            foreach ($req->data()['messages'][0]['content'] ?? [] as $block) {
                if (($block['source']['media_type'] ?? '') === 'application/pdf') {
                    return true;
                }
            }

            return false;
        });
    }

    // ── Gemini integration ───────────────────────────────────────────────────

    public function test_gemini_auto_converts_docx_to_pdf_inline_data(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
        ])]);

        (new GeminiClient(apiKey: 'test'))
            ->converseWithInlineFile($this->makeDocxBase64(), self::DOCX_MIME, 'Summarize.');

        Http::assertSent(function (Request $req) {
            foreach ($req->data()['contents'][0]['parts'] ?? [] as $part) {
                if (($part['inline_data']['mime_type'] ?? '') === 'application/pdf'
                    && str_starts_with(
                        (string) base64_decode($part['inline_data']['data'] ?? '', true),
                        '%PDF-',
                    )
                ) {
                    return true;
                }
            }

            return false;
        });
    }
}
