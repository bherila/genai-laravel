<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class GeminiClientTest extends TestCase
{
    private function makeClient(): GeminiClient
    {
        return new GeminiClient('test-api-key');
    }

    // ── provider / static ────────────────────────────────────────────────────

    public function test_provider_returns_gemini(): void
    {
        $this->assertSame('gemini', $this->makeClient()->provider());
    }

    public function test_max_file_bytes_is_20_mb(): void
    {
        $this->assertSame(20 * 1024 * 1024, GeminiClient::maxFileBytes());
    }

    // ── uploadFile ───────────────────────────────────────────────────────────

    public function test_upload_file_returns_uri_on_success(): void
    {
        Http::fake([
            '*upload*' => Http::response(['file' => ['uri' => 'files/abc123xyz']], 200),
        ]);

        $uri = $this->makeClient()->uploadFile('fake content', 'application/pdf', 'test.pdf');
        $this->assertSame('files/abc123xyz', $uri);
    }

    public function test_upload_file_falls_back_to_file_name_field(): void
    {
        Http::fake([
            '*upload*' => Http::response(['file' => ['name' => 'files/fallback456']], 200),
        ]);

        $uri = $this->makeClient()->uploadFile('content', 'text/csv');
        $this->assertSame('files/fallback456', $uri);
    }

    public function test_upload_file_returns_null_on_server_error(): void
    {
        Http::fake(['*upload*' => Http::response(['error' => 'internal'], 500)]);

        $result = $this->makeClient()->uploadFile('bytes', 'application/pdf');
        $this->assertNull($result);
    }

    public function test_upload_file_throws_fatal_on_400(): void
    {
        Http::fake(['*upload*' => Http::response(['error' => 'bad file'], 400)]);

        $this->expectException(GenAiFatalException::class);
        $this->expectExceptionMessageMatches('/File rejected by Gemini/');
        $this->makeClient()->uploadFile('bytes', 'application/pdf');
    }

    public function test_upload_file_sends_api_key_header(): void
    {
        Http::fake(['*upload*' => Http::response(['file' => ['uri' => 'files/x']])]);

        $this->makeClient()->uploadFile('bytes', 'application/pdf');

        Http::assertSent(fn (Request $req) => $req->header('x-goog-api-key')[0] === 'test-api-key');
    }

    // ── deleteFile ───────────────────────────────────────────────────────────

    public function test_delete_file_calls_correct_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->makeClient()->deleteFile('files/abc123');

        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'files/abc123')
            && $req->method() === 'DELETE');
    }

    public function test_delete_file_extracts_file_path_from_full_uri(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->makeClient()->deleteFile('https://generativelanguage.googleapis.com/v1beta/files/abc123');

        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'files/abc123'));
    }

    public function test_delete_file_swallows_exceptions(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        // Should not throw
        $this->makeClient()->deleteFile('files/abc');
        $this->addToAssertionCount(1);
    }

    // ── converseWithFileRef ───────────────────────────────────────────────────

    public function test_converse_with_file_ref_sends_file_data_block(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithFileRef('files/abc', 'application/pdf', 'Extract data.');

        Http::assertSent(function (Request $req) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];
            foreach ($parts as $part) {
                if (($part['file_data']['file_uri'] ?? null) === 'files/abc') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_converse_with_file_ref_sends_prompt_as_text_part(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithFileRef('files/abc', 'application/pdf', 'My prompt');

        Http::assertSent(function (Request $req) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];
            foreach ($parts as $part) {
                if (($part['text'] ?? null) === 'My prompt') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_converse_with_file_ref_puts_file_before_text(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithFileRef('files/abc', 'application/pdf', 'prompt');

        Http::assertSent(function (Request $req) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];

            return isset($parts[0]['file_data']) && isset($parts[1]['text']);
        });
    }

    public function test_converse_with_file_ref_applies_tool_config(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_fn', 'desc', Schema::object(['x' => Schema::string()]))],
            choice: ToolChoice::any(),
        );

        $this->makeClient()->converseWithFileRef('files/abc', 'application/pdf', 'prompt', $toolConfig);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return isset($body['tools']) && isset($body['toolConfig'])
                && ! isset($body['generationConfig']);
        });
    }

    public function test_converse_with_file_ref_uses_json_mode_when_no_tool_config(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithFileRef('files/abc', 'application/pdf', 'prompt');

        Http::assertSent(function (Request $req) {
            return ($req->data()['generationConfig']['response_mime_type'] ?? null) === 'application/json';
        });
    }

    // ── converseWithInlineFile ────────────────────────────────────────────────

    public function test_converse_with_inline_file_embeds_base64(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $base64 = base64_encode('pdf bytes');
        $this->makeClient()->converseWithInlineFile($base64, 'application/pdf', 'Extract.');

        Http::assertSent(function (Request $req) use ($base64) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];
            foreach ($parts as $part) {
                if (($part['inline_data']['data'] ?? null) === $base64) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_converse_with_inline_file_puts_file_before_text(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithInlineFile(base64_encode('bytes'), 'application/pdf', 'prompt');

        Http::assertSent(function (Request $req) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];

            return isset($parts[0]['inline_data']) && isset($parts[1]['text']);
        });
    }

    public function test_converse_with_inline_file_sends_system_instruction(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithInlineFile(
            base64_encode('bytes'),
            'application/pdf',
            'prompt',
            'You are an expert.',
        );

        Http::assertSent(function (Request $req) {
            return ($req->data()['systemInstruction']['parts'][0]['text'] ?? null) === 'You are an expert.';
        });
    }

    public function test_converse_with_inline_file_omits_system_instruction_when_empty(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converseWithInlineFile(base64_encode('bytes'), 'application/pdf', 'prompt');

        Http::assertSent(fn (Request $req) => ! array_key_exists('systemInstruction', $req->data()));
    }

    // ── converse ─────────────────────────────────────────────────────────────

    public function test_converse_maps_assistant_role_to_model(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converse('', [
            ['role' => 'user', 'content' => [ContentBlock::text('Hello')]],
            ['role' => 'assistant', 'content' => [ContentBlock::text('Hi')]],
        ]);

        Http::assertSent(function (Request $req) {
            $contents = $req->data()['contents'];

            return $contents[1]['role'] === 'model';
        });
    }

    public function test_converse_sends_system_instruction(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converse('Be concise.', [
            ['role' => 'user', 'content' => [ContentBlock::text('hi')]],
        ]);

        Http::assertSent(function (Request $req) {
            return ($req->data()['systemInstruction']['parts'][0]['text'] ?? null) === 'Be concise.';
        });
    }

    public function test_converse_omits_system_instruction_when_empty(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $this->makeClient()->converse('', [
            ['role' => 'user', 'content' => [ContentBlock::text('hi')]],
        ]);

        Http::assertSent(fn (Request $req) => ! array_key_exists('systemInstruction', $req->data()));
    }

    public function test_converse_document_block_renders_as_inline_data(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $base64 = base64_encode('pdf');
        $this->makeClient()->converse('', [
            ['role' => 'user', 'content' => [
                ContentBlock::document($base64, 'application/pdf'),
                ContentBlock::text('Summarize.'),
            ]],
        ]);

        Http::assertSent(function (Request $req) use ($base64) {
            $parts = $req->data()['contents'][0]['parts'] ?? [];
            foreach ($parts as $part) {
                if (($part['inline_data']['data'] ?? null) === $base64) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_tool_config_converts_schema_to_gemini_uppercase(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('extract', 'Extract data', Schema::object([
                'amount' => Schema::number('Dollar amount'),
                'label' => Schema::string(),
            ], required: ['amount']))],
            choice: ToolChoice::any(),
        );

        $this->makeClient()->converse('', [
            ['role' => 'user', 'content' => [ContentBlock::text('hi')]],
        ], $toolConfig);

        Http::assertSent(function (Request $req) {
            $decls = $req->data()['tools'][0]['function_declarations'][0] ?? [];
            $params = $decls['parameters'] ?? [];

            return ($params['type'] ?? '') === 'OBJECT'
                && ($params['properties']['amount']['type'] ?? '') === 'NUMBER'
                && ($params['properties']['label']['type'] ?? '') === 'STRING'
                && ($req->data()['toolConfig']['functionCallingConfig']['mode'] ?? '') === 'ANY';
        });
    }

    public function test_tool_choice_tool_sets_allowed_function_names(): void
    {
        Http::fake(['*generateContent*' => Http::response($this->emptyResponse())]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_fn', 'desc', Schema::object([]))],
            choice: ToolChoice::tool('my_fn'),
        );

        $this->makeClient()->converse('', [
            ['role' => 'user', 'content' => [ContentBlock::text('hi')]],
        ], $toolConfig);

        Http::assertSent(function (Request $req) {
            $cfg = $req->data()['toolConfig']['functionCallingConfig'] ?? [];

            return ($cfg['mode'] ?? '') === 'ANY'
                && ($cfg['allowedFunctionNames'][0] ?? '') === 'my_fn';
        });
    }

    // ── error handling ────────────────────────────────────────────────────────

    public function test_throws_rate_limit_exception_on_429(): void
    {
        Http::fake(['*generateContent*' => Http::response([], 429)]);

        $this->expectException(GenAiRateLimitException::class);
        $this->makeClient()->converseWithFileRef('files/x', 'application/pdf', 'prompt');
    }

    public function test_throws_fatal_exception_on_400(): void
    {
        Http::fake(['*generateContent*' => Http::response(['error' => 'bad'], 400)]);

        $this->expectException(GenAiFatalException::class);
        $this->makeClient()->converseWithFileRef('files/x', 'application/pdf', 'prompt');
    }

    // ── extractText ───────────────────────────────────────────────────────────

    public function test_extract_text_concatenates_all_text_parts(): void
    {
        $response = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'Hello '],
                    ['text' => 'world'],
                ]],
            ]],
        ];

        $this->assertSame('Hello world', $this->makeClient()->extractText($response));
    }

    public function test_extract_text_ignores_function_call_parts(): void
    {
        $response = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['functionCall' => ['name' => 'my_fn', 'args' => []]],
                    ['text' => 'only text'],
                ]],
            ]],
        ];

        $this->assertSame('only text', $this->makeClient()->extractText($response));
    }

    public function test_extract_text_returns_empty_string_for_empty_response(): void
    {
        $this->assertSame('', $this->makeClient()->extractText([]));
    }

    // ── extractToolCalls ──────────────────────────────────────────────────────

    public function test_extract_tool_calls_returns_function_calls(): void
    {
        $response = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['functionCall' => ['name' => 'classify_document', 'args' => ['document_type' => 'p_and_l']]],
                ]],
            ]],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(1, $calls);
        $this->assertSame('classify_document', $calls[0]['name']);
        $this->assertSame('p_and_l', $calls[0]['input']['document_type']);
    }

    public function test_extract_tool_calls_returns_multiple_calls(): void
    {
        $response = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['functionCall' => ['name' => 'fn_a', 'args' => ['x' => 1]]],
                    ['functionCall' => ['name' => 'fn_b', 'args' => ['y' => 2]]],
                ]],
            ]],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(2, $calls);
        $this->assertSame('fn_a', $calls[0]['name']);
        $this->assertSame('fn_b', $calls[1]['name']);
    }

    public function test_extract_tool_calls_ignores_text_parts(): void
    {
        $response = [
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'thinking out loud'],
                    ['functionCall' => ['name' => 'my_fn', 'args' => []]],
                ]],
            ]],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(1, $calls);
    }

    public function test_extract_tool_calls_returns_empty_for_text_only_response(): void
    {
        $response = [
            'candidates' => [[
                'content' => ['parts' => [['text' => 'no tools']]],
            ]],
        ];

        $this->assertSame([], $this->makeClient()->extractToolCalls($response));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function emptyResponse(): array
    {
        return ['candidates' => [['content' => ['parts' => []]]]];
    }
}
