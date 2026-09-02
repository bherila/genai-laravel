<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\Exceptions\GenAiUploadException;
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

    public function test_inline_limit_is_the_request_budget_for_documents(): void
    {
        // 32 MB request limit, less the third base64 adds.
        $this->assertSame(intdiv(32 * 1024 * 1024 * 3, 4), AnthropicClient::maxInlineFileBytes('application/pdf'));
    }

    public function test_inline_limit_is_lower_for_images(): void
    {
        $this->assertSame(5 * 1024 * 1024, AnthropicClient::maxInlineFileBytes('image/png'));
    }

    public function test_uploaded_file_limit_is_the_files_api_ceiling(): void
    {
        $this->assertSame(500 * 1024 * 1024, AnthropicClient::maxUploadedFileBytes());
    }

    public function test_no_documented_per_message_block_cap(): void
    {
        $this->assertNull(AnthropicClient::maxInlineBlocksPerMessage('application/pdf'));
    }

    public function test_request_ceiling_is_the_messages_api_limit(): void
    {
        // A file at the per-block limit already fills this, which is exactly why
        // the per-block check cannot be the only one. Enforcement is exercised in
        // FileLimitsTest, where the ceiling can be small enough not to allocate
        // 32 MB inside a unit test.
        $this->assertSame(32 * 1024 * 1024, AnthropicClient::maxRequestBytes());
        // Exactly: a single file at the per-block limit base64-expands to the
        // entire request budget, leaving nothing for prompt, tools or history.
        $this->assertSame(
            AnthropicClient::maxRequestBytes(),
            (int) (AnthropicClient::maxInlineFileBytes('application/pdf') * 4 / 3),
        );
    }

    // ── Files API ────────────────────────────────────────────────────────────

    public function test_supports_the_files_api(): void
    {
        $this->assertTrue(AnthropicClient::supportsFileApi());
    }

    public function test_upload_file_posts_multipart_and_returns_the_file_id(): void
    {
        Http::fake(['*' => Http::response(['id' => 'file_011CNha8iCJcU1wXNR6q4V8w', 'type' => 'file'])]);

        $id = $this->makeClient()->uploadFile('report bytes', 'application/pdf', 'report.pdf');

        $this->assertSame('file_011CNha8iCJcU1wXNR6q4V8w', $id);

        Http::assertSent(function (Request $req) {
            return str_ends_with($req->url(), '/v1/files')
                && $req->method() === 'POST'
                && $req->isMultipart()
                && $req->header('anthropic-beta')[0] === 'files-api-2025-04-14'
                && $req->header('x-api-key')[0] === 'test-key';
        });
    }

    public function test_upload_file_throws_upload_exception_on_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 413)]);

        try {
            $this->makeClient()->uploadFile('bytes', 'application/pdf');
            $this->fail('Expected GenAiUploadException.');
        } catch (GenAiUploadException $e) {
            $this->assertSame(413, $e->status);
        }
    }

    public function test_upload_file_rejects_a_file_over_the_files_api_limit(): void
    {
        Http::fake(['*' => Http::response(['id' => 'file_x'])]);

        // A sparse file: fstat reports 500 MB + 1 without writing 500 MB.
        $path = tempnam(sys_get_temp_dir(), 'genai_oversize_');
        $stream = fopen($path, 'r+');
        ftruncate($stream, (int) AnthropicClient::maxUploadedFileBytes() + 1);

        try {
            $this->makeClient()->uploadFile($stream, 'application/pdf');
            $this->fail('Expected GenAiFileTooLargeException.');
        } catch (GenAiFileTooLargeException $e) {
            $this->assertSame(500 * 1024 * 1024, $e->limitBytes);
        } finally {
            fclose($stream);
            @unlink($path);
        }

        Http::assertNothingSent();
    }

    public function test_delete_file_calls_the_files_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->makeClient()->deleteFile('file_abc123');

        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE'
            && str_ends_with($req->url(), '/v1/files/file_abc123'));
    }

    public function test_delete_file_does_not_throw_on_failure(): void
    {
        // deleteFile() usually runs in a finally block; throwing there would
        // replace whatever error sent us into cleanup.
        Http::fake(['*' => Http::response(['error' => 'gone'], 404)]);

        $this->makeClient()->deleteFile('file_missing');

        $this->addToAssertionCount(1);
    }

    public function test_converse_with_file_ref_sends_a_file_source_document_block(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converseWithFileRef('file_abc123', 'application/pdf', 'Summarize.');

        Http::assertSent(function (Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];

            return ($content[0]['type'] ?? '') === 'document'
                && ($content[0]['source']['type'] ?? '') === 'file'
                && ($content[0]['source']['file_id'] ?? '') === 'file_abc123'
                && ($content[1]['text'] ?? '') === 'Summarize.';
        });
    }

    public function test_file_reference_to_an_image_uses_the_image_block(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::fileReference('file_img', 'image/png')],
        ]]);

        Http::assertSent(function (Request $req) {
            $block = $req->data()['messages'][0]['content'][0] ?? [];

            return ($block['type'] ?? '') === 'image'
                && ($block['source']['file_id'] ?? '') === 'file_img';
        });
    }

    public function test_file_reference_requests_carry_the_files_beta_header(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->makeClient()->converseWithFileRef('file_abc123', 'application/pdf', 'Summarize.');

        Http::assertSent(fn (Request $req) => ($req->header('anthropic-beta')[0] ?? '') === 'files-api-2025-04-14');
    }

    public function test_plain_requests_do_not_carry_the_files_beta_header(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $client = $this->makeClient();
        // Ordered deliberately: a file request must not leave the beta header
        // attached to the client for every later call.
        $client->converseWithFileRef('file_abc123', 'application/pdf', 'Summarize.');
        $client->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        [$last] = Http::recorded()->last();

        $this->assertSame([], $last->header('anthropic-beta'));
    }

    public function test_list_files_paginates(): void
    {
        $calls = 0;
        Http::fake(['*' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['data' => [['id' => 'file_a']], 'has_more' => true, 'last_id' => 'file_a'])
                : Http::response(['data' => [['id' => 'file_b']], 'has_more' => false]);
        }]);

        $files = $this->makeClient()->listFiles();

        $this->assertSame(['file_a', 'file_b'], array_column($files, 'id'));
    }

    public function test_file_metadata_returns_the_provider_entry(): void
    {
        Http::fake(['*' => Http::response(['id' => 'file_abc', 'filename' => 'report.pdf', 'size_bytes' => 12])]);

        $meta = $this->makeClient()->fileMetadata('file_abc');

        $this->assertSame('report.pdf', $meta['filename']);
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

    public function test_converse_with_inline_text_file_sends_a_text_source_not_base64(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $plain = "Line one\nLine two\n";
        $this->makeClient()->converseWithInlineFile(base64_encode($plain), 'text/plain', 'Summarize.');

        Http::assertSent(function (Request $req) use ($plain) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'document') {
                    continue;
                }

                return ($block['source']['type'] ?? '') === 'text'
                    && ($block['source']['media_type'] ?? '') === 'text/plain'
                    && ($block['source']['data'] ?? '') === $plain;
            }

            return false;
        });
    }

    public function test_text_document_block_never_sends_a_base64_source(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $base64 = base64_encode('some notes');
        $this->makeClient()->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document($base64, 'text/plain')],
        ]]);

        Http::assertSent(function (Request $req) use ($base64) {
            $raw = $req->body();

            return ! str_contains($raw, '"base64"') && ! str_contains($raw, $base64);
        });
    }

    public function test_text_document_block_rejects_content_that_is_not_base64(): void
    {
        Http::fake(['*' => Http::response($this->fakeTextResponse())]);

        $this->expectException(GenAiFatalException::class);

        $this->makeClient()->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::document('not base64 !!!', 'text/plain')],
        ]]);
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
