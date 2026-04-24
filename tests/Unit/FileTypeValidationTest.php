<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class FileTypeValidationTest extends TestCase
{
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    // ── Anthropic ────────────────────────────────────────────────────────────

    public function test_anthropic_supported_mime_types_list(): void
    {
        $this->assertSame(
            ['application/pdf', 'text/plain'],
            AnthropicClient::supportedDocumentMimeTypes(),
        );
    }

    public function test_anthropic_is_supported_mime_type(): void
    {
        $this->assertTrue(AnthropicClient::isSupportedDocumentMimeType('application/pdf'));
        $this->assertTrue(AnthropicClient::isSupportedDocumentMimeType('text/plain'));
        $this->assertFalse(AnthropicClient::isSupportedDocumentMimeType(self::DOCX_MIME));
        $this->assertFalse(AnthropicClient::isSupportedDocumentMimeType('text/csv'));
    }

    public function test_anthropic_throws_on_malformed_docx(): void
    {
        // Sanity check: if neither phpword nor a PDF renderer were installed,
        // the client would throw upfront. With the dev-deps present, this path
        // is exercised only by feeding bytes the reader cannot parse.
        Http::fake();
        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');

        $this->expectException(GenAiFatalException::class);
        // Malformed bytes → reader fails → GenAiFatalException from the converter.
        $client->converseWithInlineFile(base64_encode('not a real docx'), self::DOCX_MIME, 'Summarize.');
    }

    public function test_anthropic_rejects_unknown_mime_without_http_call(): void
    {
        Http::fake();
        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');

        try {
            $client->converse('', [[
                'role' => 'user',
                'content' => [ContentBlock::document(base64_encode('x'), 'application/octet-stream')],
            ]]);
            $this->fail('Expected GenAiFatalException');
        } catch (GenAiFatalException $e) {
            $this->assertStringContainsString('application/octet-stream', $e->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_anthropic_routes_png_to_image_block(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $pngB64 = base64_encode('fake-png-bytes');
        (new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6'))
            ->converseWithInlineFile($pngB64, 'image/png', 'Describe this.');

        Http::assertSent(function (\Illuminate\Http\Client\Request $req) use ($pngB64) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'image'
                    && ($block['source']['media_type'] ?? '') === 'image/png'
                    && ($block['source']['data'] ?? '') === $pngB64) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_anthropic_supported_image_types(): void
    {
        $this->assertSame(
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            AnthropicClient::supportedImageMimeTypes(),
        );
    }

    public function test_anthropic_accepts_pdf(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        (new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6'))
            ->converseWithInlineFile(base64_encode('pdf'), 'application/pdf', 'Summarize.');

        Http::assertSentCount(1);
    }

    public function test_anthropic_accepts_text_plain(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'model' => 'claude-sonnet-4-6', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        (new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6'))
            ->converseWithInlineFile(base64_encode('hello'), 'text/plain', 'Summarize.');

        Http::assertSentCount(1);
    }

    // ── Bedrock ──────────────────────────────────────────────────────────────

    public function test_bedrock_supported_mime_types_include_office_formats(): void
    {
        $types = BedrockClient::supportedDocumentMimeTypes();

        // Bedrock natively handles the full Office suite — unlike Anthropic and Gemini.
        $this->assertContains('application/pdf', $types);
        $this->assertContains(self::DOCX_MIME, $types);
        $this->assertContains(self::XLSX_MIME, $types);
        $this->assertContains('text/csv', $types);
        $this->assertContains('text/markdown', $types);
    }

    public function test_bedrock_accepts_docx(): void
    {
        Http::fake(['*' => Http::response([
            'output' => ['message' => ['content' => [['text' => 'ok']]]],
        ])]);

        (new BedrockClient(apiKey: 'test', modelId: 'model'))
            ->converseWithInlineFile(base64_encode('docx'), self::DOCX_MIME, 'Summarize.');

        Http::assertSentCount(1);
    }

    public function test_bedrock_routes_png_to_image_block(): void
    {
        Http::fake(['*' => Http::response([
            'output' => ['message' => ['content' => [['text' => 'ok']]]],
        ])]);

        (new BedrockClient(apiKey: 'test', modelId: 'model'))
            ->converseWithInlineFile(base64_encode('png'), 'image/png', 'Describe.');

        Http::assertSent(function (\Illuminate\Http\Client\Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['image']['format'] ?? '') === 'png') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_bedrock_rejects_truly_unknown_mime(): void
    {
        Http::fake();
        $client = new BedrockClient(apiKey: 'test', modelId: 'model');

        $this->expectException(GenAiFatalException::class);
        $this->expectExceptionMessageMatches('/Bedrock Converse does not accept application\/octet-stream/');

        $client->converseWithInlineFile(base64_encode('junk'), 'application/octet-stream', 'x');
    }


    // ── Gemini ───────────────────────────────────────────────────────────────

    public function test_gemini_supported_mime_types(): void
    {
        $types = GeminiClient::supportedDocumentMimeTypes();

        $this->assertContains('application/pdf', $types);
        $this->assertContains('text/plain', $types);
        $this->assertContains('text/markdown', $types);
        $this->assertContains('text/html', $types);
        $this->assertContains('image/png', $types);
        $this->assertContains('image/jpeg', $types);
        $this->assertNotContains(self::DOCX_MIME, $types);
        $this->assertNotContains(self::XLSX_MIME, $types);
    }

    public function test_gemini_accepts_png(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
        ])]);

        (new GeminiClient(apiKey: 'test'))
            ->converseWithInlineFile(base64_encode('png'), 'image/png', 'Describe.');

        Http::assertSentCount(1);
    }

    public function test_gemini_rejects_malformed_docx(): void
    {
        Http::fake();
        $client = new GeminiClient(apiKey: 'test');

        $this->expectException(GenAiFatalException::class);
        $client->converseWithInlineFile(base64_encode('not a real docx'), self::DOCX_MIME, 'Summarize.');
    }

    public function test_gemini_accepts_pdf(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
        ])]);

        (new GeminiClient(apiKey: 'test'))
            ->converseWithInlineFile(base64_encode('pdf'), 'application/pdf', 'Summarize.');

        Http::assertSentCount(1);
    }

    public function test_gemini_accepts_markdown(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']]]]],
        ])]);

        (new GeminiClient(apiKey: 'test'))
            ->converseWithInlineFile(base64_encode('# hi'), 'text/markdown', 'Read it.');

        Http::assertSentCount(1);
    }
}
