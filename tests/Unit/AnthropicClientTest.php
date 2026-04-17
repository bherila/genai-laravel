<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
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

class AnthropicClientTest extends TestCase
{
    private function makeClient(): AnthropicClient
    {
        return new AnthropicClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
        );
    }

    private function fakeTextResponse(string $text = 'Hello!'): array
    {
        return [
            'id' => 'msg_01abc',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    // ── provider / static ────────────────────────────────────────────────────

    public function test_provider_returns_anthropic(): void
    {
        $this->assertSame('anthropic', $this->makeClient()->provider());
    }

    public function test_max_file_bytes_is_4_5_mb(): void
    {
        $this->assertSame(4_718_592, AnthropicClient::maxFileBytes());
    }

    // ── upload / delete (no-ops) ─────────────────────────────────────────────

    public function test_upload_file_returns_null(): void
    {
        $this->assertNull($this->makeClient()->uploadFile('bytes', 'application/pdf'));
    }

    public function test_delete_file_is_noop(): void
    {
        $this->makeClient()->deleteFile('files/abc');
        $this->addToAssertionCount(1);
    }

    public function test_converse_with_file_ref_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->makeClient()->converseWithFileRef('files/abc', 'application/pdf', 'test');
    }

    // ── converse ─────────────────────────────────────────────────────────────

    public function test_converse_sends_correct_headers(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(function (Request $req) {
            return $req->header('x-api-key')[0] === 'test-key'
                && $req->header('anthropic-version')[0] === '2023-06-01';
        });
    }

    public function test_converse_sends_to_messages_endpoint(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(fn (Request $req) => str_ends_with($req->url(), '/v1/messages'));
    }

    public function test_converse_sends_model_and_max_tokens(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return $body['model'] === 'claude-sonnet-4-6' && isset($body['max_tokens']);
        });
    }

    public function test_converse_sends_system_as_content_block_array(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse(
            system: 'You are helpful.',
            messages: [['role' => 'user', 'content' => [ContentBlock::text('hi')]]],
        );

        Http::assertSent(function (Request $req) {
            $system = $req->data()['system'] ?? [];

            return ($system[0]['type'] ?? '') === 'text'
                && ($system[0]['text'] ?? '') === 'You are helpful.';
        });
    }

    public function test_converse_omits_system_when_empty(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(fn (Request $req) => ! array_key_exists('system', $req->data()));
    }

    public function test_converse_includes_tools_when_provided(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_tool', 'test', Schema::object([]))],
            choice: ToolChoice::auto(),
        );

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]], $toolConfig);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return isset($body['tools']) && isset($body['tool_choice']);
        });
    }

    public function test_converse_omits_tools_when_null(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]], null);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return ! array_key_exists('tools', $body) && ! array_key_exists('tool_choice', $body);
        });
    }

    public function test_tool_config_converts_to_anthropic_format(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('extract', 'Extract data', Schema::object([
                'amount' => Schema::number('Dollar amount'),
            ], required: ['amount']))],
            choice: ToolChoice::any(),
        );

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]], $toolConfig);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return ($body['tools'][0]['name'] ?? '') === 'extract'
                && isset($body['tools'][0]['input_schema'])
                && ($body['tool_choice']['type'] ?? '') === 'any';
        });
    }

    public function test_converse_throws_rate_limit_exception_on_429(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'rate_limit_error']], 429)]);

        $this->expectException(GenAiRateLimitException::class);
        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
    }

    public function test_converse_throws_fatal_exception_on_400(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'invalid_request_error']], 400)]);

        $this->expectException(GenAiFatalException::class);
        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
    }

    public function test_converse_throws_fatal_exception_on_403(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'permission_error']], 403)]);

        $this->expectException(GenAiFatalException::class);
        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
    }

    // ── converseWithInlineFile ────────────────────────────────────────────────

    public function test_converse_with_inline_file_embeds_base64_document_block(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $base64 = base64_encode('fake pdf bytes');
        $this->makeClient()->converseWithInlineFile($base64, 'application/pdf', 'Summarize.');

        Http::assertSent(function (Request $req) use ($base64) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (
                    ($block['type'] ?? '') === 'document'
                    && ($block['source']['type'] ?? '') === 'base64'
                    && ($block['source']['data'] ?? '') === $base64
                    && ($block['source']['media_type'] ?? '') === 'application/pdf'
                ) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_converse_with_inline_file_appends_text_prompt(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converseWithInlineFile(base64_encode('data'), 'application/pdf', 'What is this?');

        Http::assertSent(function (Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text' && ($block['text'] ?? '') === 'What is this?') {
                    return true;
                }
            }

            return false;
        });
    }

    // ── extractText ───────────────────────────────────────────────────────────

    public function test_extract_text_returns_concatenated_text_blocks(): void
    {
        $response = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'world'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'ignored', 'input' => []],
            ],
        ];

        $this->assertSame('Hello world', $this->makeClient()->extractText($response));
    }

    public function test_extract_text_returns_empty_string_for_missing_content(): void
    {
        $this->assertSame('', $this->makeClient()->extractText([]));
    }

    // ── extractToolCalls ──────────────────────────────────────────────────────

    public function test_extract_tool_calls_parses_single_tool(): void
    {
        $response = [
            'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'classify_document', 'input' => ['document_type' => 'invoice']],
            ],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(1, $calls);
        $this->assertSame('classify_document', $calls[0]['name']);
        $this->assertSame('invoice', $calls[0]['input']['document_type']);
    }

    public function test_extract_tool_calls_parses_multiple_tools(): void
    {
        $response = [
            'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'classify_document', 'input' => ['document_type' => 'p_and_l']],
                ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'extract_data', 'input' => ['total' => 50000]],
            ],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(2, $calls);
        $this->assertSame('classify_document', $calls[0]['name']);
        $this->assertSame('extract_data', $calls[1]['name']);
        $this->assertSame(50000, $calls[1]['input']['total']);
    }

    public function test_extract_tool_calls_ignores_non_tool_blocks(): void
    {
        $response = [
            'content' => [
                ['type' => 'text', 'text' => 'Some text'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'my_tool', 'input' => []],
            ],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(1, $calls);
    }

    public function test_extract_tool_calls_returns_empty_array_when_no_tools(): void
    {
        $response = ['content' => [['type' => 'text', 'text' => 'no tools here']]];
        $this->assertSame([], $this->makeClient()->extractToolCalls($response));
    }
}
