<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiResponse;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class GenAiRequestTest extends TestCase
{
    // ── AnthropicClient via GenAiRequest ─────────────────────────────────────

    private function fakeAnthropicResponse(string $text = 'Hello!'): array
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

    private function makeAnthropicClient(): \Bherila\GenAiLaravel\Clients\AnthropicClient
    {
        return new \Bherila\GenAiLaravel\Clients\AnthropicClient(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
        );
    }

    public function test_generate_returns_gen_ai_response(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse('Hello world'))]);

        $response = GenAiRequest::with($this->makeAnthropicClient())
            ->system('Be concise.')
            ->prompt('Say hello.')
            ->generate();

        $this->assertInstanceOf(GenAiResponse::class, $response);
        $this->assertSame('Hello world', $response->text);
        $this->assertSame([], $response->toolCalls);
        $this->assertFalse($response->hasToolCalls());
    }

    public function test_generate_sends_system_and_prompt(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse())]);

        GenAiRequest::with($this->makeAnthropicClient())
            ->system('You are helpful.')
            ->prompt('Hi there.')
            ->generate();

        Http::assertSent(function (Request $req) {
            $body = $req->data();

            return ($body['system'][0]['text'] ?? '') === 'You are helpful.'
                && ($body['messages'][0]['content'][0]['text'] ?? '') === 'Hi there.';
        });
    }

    public function test_generate_with_file_puts_document_before_prompt(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse())]);

        $base64 = base64_encode('pdf bytes');
        GenAiRequest::with($this->makeAnthropicClient())
            ->withFile($base64, 'application/pdf')
            ->prompt('Summarize.')
            ->generate();

        Http::assertSent(function (Request $req) use ($base64) {
            $content = $req->data()['messages'][0]['content'] ?? [];

            return ($content[0]['type'] ?? '') === 'document'
                && ($content[0]['source']['data'] ?? '') === $base64
                && ($content[1]['type'] ?? '') === 'text'
                && ($content[1]['text'] ?? '') === 'Summarize.';
        });
    }

    public function test_generate_with_files_sends_all_files(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse())]);

        $b1 = base64_encode('file1');
        $b2 = base64_encode('file2');
        GenAiRequest::with($this->makeAnthropicClient())
            ->withFiles([
                ['base64' => $b1, 'mimeType' => 'application/pdf'],
                ['base64' => $b2, 'mimeType' => 'application/pdf'],
            ])
            ->prompt('Compare.')
            ->generate();

        Http::assertSent(function (Request $req) use ($b1, $b2) {
            $content = $req->data()['messages'][0]['content'] ?? [];

            return count($content) === 3
                && ($content[0]['source']['data'] ?? '') === $b1
                && ($content[1]['source']['data'] ?? '') === $b2
                && ($content[2]['type'] ?? '') === 'text';
        });
    }

    public function test_generate_with_tools(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse())]);

        $toolConfig = new ToolConfig(
            tools: [new ToolDefinition('my_fn', 'desc', Schema::object([]))],
            choice: ToolChoice::any(),
        );

        GenAiRequest::with($this->makeAnthropicClient())
            ->prompt('Call a tool.')
            ->tools($toolConfig)
            ->generate();

        Http::assertSent(fn (Request $req) => isset($req->data()['tools']));
    }

    public function test_generate_with_raw_messages(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse())]);

        $messages = [
            ['role' => 'user', 'content' => [ContentBlock::text('turn 1')]],
            ['role' => 'assistant', 'content' => [ContentBlock::text('response 1')]],
            ['role' => 'user', 'content' => [ContentBlock::text('turn 2')]],
        ];

        GenAiRequest::with($this->makeAnthropicClient())
            ->messages($messages)
            ->generate();

        Http::assertSent(function (Request $req) {
            return count($req->data()['messages'] ?? []) === 3;
        });
    }

    public function test_builder_is_immutable(): void
    {
        Http::fake(['*' => Http::response($this->fakeAnthropicResponse('A'))]);

        $base = GenAiRequest::with($this->makeAnthropicClient())->system('base system');
        $req1 = $base->system('override 1');
        $req2 = $base->system('override 2');

        $this->assertNotSame($req1, $req2);
        $this->assertNotSame($base, $req1);
    }

    // ── GenAiResponse helpers ─────────────────────────────────────────────────

    public function test_response_first_tool_call(): void
    {
        $response = new GenAiResponse(
            text: '',
            toolCalls: [
                ['name' => 'fn_a', 'input' => ['x' => 1]],
                ['name' => 'fn_b', 'input' => ['y' => 2]],
            ],
            raw: [],
        );

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('fn_a', $response->firstToolCall()['name']);
    }

    public function test_response_tool_call_by_name(): void
    {
        $response = new GenAiResponse(
            text: '',
            toolCalls: [
                ['name' => 'fn_a', 'input' => []],
                ['name' => 'fn_b', 'input' => ['y' => 42]],
            ],
            raw: [],
        );

        $call = $response->toolCallByName('fn_b');
        $this->assertNotNull($call);
        $this->assertSame(42, $call['input']['y']);
        $this->assertNull($response->toolCallByName('fn_c'));
    }

    public function test_response_has_no_tool_calls_when_empty(): void
    {
        $response = new GenAiResponse(text: 'hi', toolCalls: [], raw: []);
        $this->assertFalse($response->hasToolCalls());
        $this->assertNull($response->firstToolCall());
    }

    // ── tool calls in response ────────────────────────────────────────────────

    public function test_generate_extracts_tool_calls_into_response(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'classify', 'input' => ['type' => 'invoice']],
            ],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
        ])]);

        $response = GenAiRequest::with($this->makeAnthropicClient())
            ->prompt('Classify this.')
            ->generate();

        $this->assertTrue($response->hasToolCalls());
        $this->assertSame('classify', $response->firstToolCall()['name']);
        $this->assertSame('invoice', $response->toolCallByName('classify')['input']['type']);
    }
}
