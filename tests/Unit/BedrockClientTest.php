<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\BedrockClient;
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

class BedrockClientTest extends TestCase
{
    private function makeClient(): BedrockClient
    {
        return new BedrockClient(
            apiKey: 'test-key',
            modelId: 'us.anthropic.claude-haiku-4-20250514-v1:0',
            region: 'us-east-1',
        );
    }

    // ── provider / static ────────────────────────────────────────────────────

    public function test_provider_returns_bedrock(): void
    {
        $this->assertSame('bedrock', $this->makeClient()->provider());
    }

    public function test_default_http_timeout_supports_long_running_inference(): void
    {
        $this->assertSame(240, $this->pendingRequestOptions($this->makeClient())['timeout'] ?? null);
    }

    public function test_custom_http_timeout_can_be_configured(): void
    {
        $client = new BedrockClient(
            apiKey: 'test-key',
            modelId: 'us.anthropic.claude-haiku-4-20250514-v1:0',
            region: 'us-east-1',
            timeout: 360,
        );

        $this->assertSame(360, $this->pendingRequestOptions($client)['timeout'] ?? null);
    }

    public function test_max_file_bytes_is_4_5_mb(): void
    {
        $this->assertSame(4_718_592, BedrockClient::maxFileBytes());
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingRequestOptions(BedrockClient $client): array
    {
        $clientReflection = new \ReflectionClass($client);
        $httpProperty = $clientReflection->getProperty('http');
        $httpProperty->setAccessible(true);
        $pendingRequest = $httpProperty->getValue($client);

        $requestReflection = new \ReflectionClass($pendingRequest);
        $optionsProperty = $requestReflection->getProperty('options');
        $optionsProperty->setAccessible(true);

        /** @var array<string, mixed> */
        return $optionsProperty->getValue($pendingRequest);
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

    public function test_converse_sends_correct_payload(): void
    {
        Http::fake([
            '*' => Http::response(['output' => ['message' => ['content' => []]]], 200),
        ]);

        $this->makeClient()->converse(
            system: 'You are helpful.',
            messages: [['role' => 'user', 'content' => [ContentBlock::text('Hello')]]],
        );

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return ($body['system'][0]['text'] ?? null) === 'You are helpful.'
                && ($body['messages'][0]['role'] ?? null) === 'user'
                && ($body['messages'][0]['content'][0]['text'] ?? null) === 'Hello'
                && ! array_key_exists('toolConfig', $body);
        });
    }

    public function test_converse_omits_system_when_empty(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(fn (Request $req) => ! array_key_exists('system', $req->data()));
    }

    public function test_converse_includes_tool_config_when_provided(): void
    {
        Http::fake([
            '*' => Http::response(['output' => ['message' => ['content' => []]]], 200),
        ]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_tool', 'desc', Schema::object([]))],
            choice: ToolChoice::any(),
        );

        $this->makeClient()->converse('', [], $toolConfig);

        Http::assertSent(function (Request $req) {
            return array_key_exists('toolConfig', $req->data());
        });
    }

    public function test_converse_sends_bearer_token(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(fn (Request $req) => $req->header('Authorization')[0] === 'Bearer test-key');
    }

    public function test_converse_sends_session_token_header_when_set(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $client = new BedrockClient('key', 'model', 'us-east-1', 'my-session-token');
        $client->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);

        Http::assertSent(fn (Request $req) => $req->header('X-Amz-Security-Token')[0] === 'my-session-token');
    }

    public function test_converse_throws_rate_limit_exception_on_429(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Too Many Requests'], 429)]);

        $this->expectException(GenAiRateLimitException::class);
        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
    }

    public function test_converse_throws_fatal_exception_on_400(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad Request'], 400)]);

        $this->expectException(GenAiFatalException::class);
        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]]);
    }

    // ── converseWithInlineFile ────────────────────────────────────────────────

    public function test_converse_with_inline_file_embeds_base64_document_block(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $base64 = base64_encode('fake pdf bytes');
        $this->makeClient()->converseWithInlineFile($base64, 'application/pdf', 'Extract data.');

        Http::assertSent(function (Request $req) use ($base64) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (isset($block['document']['source']['bytes']) && $block['document']['source']['bytes'] === $base64) {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_converse_with_inline_file_maps_mime_to_format(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $this->makeClient()->converseWithInlineFile(base64_encode('csv'), 'text/csv', 'Extract.');

        Http::assertSent(function (Request $req) {
            $content = $req->data()['messages'][0]['content'] ?? [];
            foreach ($content as $block) {
                if (($block['document']['format'] ?? null) === 'csv') {
                    return true;
                }
            }

            return false;
        });
    }

    // ── tool config conversion ────────────────────────────────────────────────

    public function test_tool_config_converts_to_bedrock_tool_spec(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('extract_data', 'Extract fields', Schema::object([
                'amount' => Schema::number('Dollar amount'),
                'date' => Schema::string(),
            ], required: ['amount']))],
            choice: ToolChoice::any(),
        );

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]], $toolConfig);

        Http::assertSent(function (Request $req) {
            $tc = $req->data()['toolConfig'] ?? [];
            $spec = $tc['tools'][0]['toolSpec'] ?? [];

            return ($spec['name'] ?? '') === 'extract_data'
                && isset($spec['inputSchema']['json']['properties']);
        });
    }

    public function test_any_tool_choice_encodes_as_empty_object_not_array(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_tool', 'desc', Schema::object([]))],
            choice: ToolChoice::any(),
        );

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]], $toolConfig);

        Http::assertSent(function (Request $req) {
            $raw = $req->body();

            return str_contains($raw, '"any":{}');
        });
    }

    public function test_none_tool_choice_omits_tool_choice_key(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_tool', 'desc', Schema::object([]))],
            choice: ToolChoice::none(),
        );

        $this->makeClient()->converse('', [['role' => 'user', 'content' => [ContentBlock::text('hi')]]], $toolConfig);

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return isset($body['toolConfig']['tools']) && ! array_key_exists('toolChoice', $body['toolConfig']);
        });
    }

    // ── extractText ───────────────────────────────────────────────────────────

    public function test_extract_text_returns_concatenated_text_blocks(): void
    {
        $response = [
            'output' => ['message' => ['content' => [
                ['text' => 'Hello '],
                ['text' => 'world'],
                ['toolUse' => ['name' => 'ignored']],
            ]]],
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
            'output' => ['message' => ['content' => [
                ['toolUse' => ['name' => 'classify_document', 'input' => ['document_type' => 'p_and_l']]],
            ]]],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(1, $calls);
        $this->assertSame('classify_document', $calls[0]['name']);
        $this->assertSame('p_and_l', $calls[0]['input']['document_type']);
    }

    public function test_extract_tool_calls_parses_multiple_tools_in_one_response(): void
    {
        $response = [
            'output' => ['message' => ['content' => [
                ['toolUse' => ['name' => 'classify_document', 'input' => ['document_type' => 'p_and_l']]],
                ['toolUse' => ['name' => 'extract_p_and_l', 'input' => ['total_revenue' => 100000]]],
            ]]],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(2, $calls);
        $this->assertSame('classify_document', $calls[0]['name']);
        $this->assertSame('extract_p_and_l', $calls[1]['name']);
        $this->assertSame(100000, $calls[1]['input']['total_revenue']);
    }

    public function test_extract_tool_calls_ignores_non_tool_blocks(): void
    {
        $response = [
            'output' => ['message' => ['content' => [
                ['text' => 'Some text'],
                ['toolUse' => ['name' => 'my_tool', 'input' => []]],
            ]]],
        ];

        $calls = $this->makeClient()->extractToolCalls($response);
        $this->assertCount(1, $calls);
    }

    public function test_extract_tool_calls_returns_empty_array_when_no_tools(): void
    {
        $response = ['output' => ['message' => ['content' => [['text' => 'no tools here']]]]];
        $this->assertSame([], $this->makeClient()->extractToolCalls($response));
    }
}
