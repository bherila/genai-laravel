<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\GenAiRequest;
use Bherila\GenAiLaravel\GenAiResponse;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * A tool loop — call the model, run the tool, hand the result back — has to work
 * without the caller writing provider-specific payloads. Before tool-call IDs and
 * tool-result blocks existed, it could not: the ID was discarded on extraction
 * and there was no neutral way to express a result.
 */
class ToolLoopTest extends TestCase
{
    // ── call IDs survive extraction ──────────────────────────────────────────

    public function test_anthropic_tool_calls_carry_their_id(): void
    {
        $calls = (new AnthropicClient(apiKey: 'k'))->extractToolCalls([
            'content' => [['type' => 'tool_use', 'id' => 'toolu_01A', 'name' => 'get_weather', 'input' => ['city' => 'Boston']]],
        ]);

        $this->assertSame('toolu_01A', $calls[0]['id']);
    }

    public function test_bedrock_tool_calls_carry_their_id(): void
    {
        $calls = (new BedrockClient(apiKey: 'k', modelId: 'm'))->extractToolCalls([
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 'tooluse_abc', 'name' => 'get_weather', 'input' => ['city' => 'Boston']]],
            ]]],
        ]);

        $this->assertSame('tooluse_abc', $calls[0]['id']);
    }

    public function test_gemini_tool_calls_report_an_empty_id_when_none_is_sent(): void
    {
        $calls = (new GeminiClient(apiKey: 'k'))->extractToolCalls([
            'candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Boston']]],
            ]]]],
        ]);

        // Gemini matches a response to its call by name; it sends an id only for
        // parallel calls, so an absent one is expected rather than a failure.
        $this->assertSame('', $calls[0]['id']);
        $this->assertSame('get_weather', $calls[0]['name']);
    }

    // ── replaying the assistant turn ─────────────────────────────────────────

    public function test_assistant_message_replays_text_and_tool_calls(): void
    {
        $response = new GenAiResponse(
            text: 'Let me check.',
            toolCalls: [['id' => 'toolu_01A', 'name' => 'get_weather', 'input' => ['city' => 'Boston']]],
            usage: Usage::empty(),
            raw: [],
        );

        $message = $response->assistantMessage();

        $this->assertSame('assistant', $message['role']);
        $this->assertSame(ContentBlock::TYPE_TEXT, $message['content'][0]->type);
        $this->assertSame(ContentBlock::TYPE_TOOL_CALL, $message['content'][1]->type);
        $this->assertSame('toolu_01A', $message['content'][1]->toolCallId);
        $this->assertSame(['city' => 'Boston'], $message['content'][1]->toolInput);
    }

    public function test_assistant_message_omits_empty_text(): void
    {
        $response = new GenAiResponse(
            text: '',
            toolCalls: [['id' => 't1', 'name' => 'fn', 'input' => []]],
            usage: Usage::empty(),
            raw: [],
        );

        $this->assertCount(1, $response->assistantMessage()['content']);
    }

    // ── wire shapes ──────────────────────────────────────────────────────────

    public function test_anthropic_round_trips_a_tool_use_and_tool_result(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'It is 12°C.']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $this->sendLoop(new AnthropicClient(apiKey: 'k'));

        Http::assertSent(function (Request $req) {
            $assistant = $req->data()['messages'][1]['content'] ?? [];
            $user = $req->data()['messages'][2]['content'] ?? [];

            return ($assistant[0]['type'] ?? '') === 'tool_use'
                && ($assistant[0]['id'] ?? '') === 'call_1'
                && ($assistant[0]['name'] ?? '') === 'get_weather'
                && ($user[0]['type'] ?? '') === 'tool_result'
                && ($user[0]['tool_use_id'] ?? '') === 'call_1'
                && ($user[0]['content'] ?? '') === '{"temp_c":12}'
                && ! array_key_exists('is_error', $user[0]);
        });
    }

    public function test_bedrock_round_trips_a_tool_use_and_tool_result(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => [['text' => 'ok']]]]])]);

        $this->sendLoop(new BedrockClient(apiKey: 'k', modelId: 'm'));

        Http::assertSent(function (Request $req) {
            $assistant = $req->data()['messages'][1]['content'] ?? [];
            $user = $req->data()['messages'][2]['content'] ?? [];

            return ($assistant[0]['toolUse']['toolUseId'] ?? '') === 'call_1'
                && ($assistant[0]['toolUse']['name'] ?? '') === 'get_weather'
                && ($user[0]['toolResult']['toolUseId'] ?? '') === 'call_1'
                && ($user[0]['toolResult']['content'][0]['json'] ?? []) === ['temp_c' => 12]
                && ($user[0]['toolResult']['status'] ?? '') === 'success';
        });
    }

    public function test_gemini_round_trips_a_function_call_and_response(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []])]);

        $this->sendLoop(new GeminiClient(apiKey: 'k'));

        Http::assertSent(function (Request $req) {
            $contents = $req->data()['contents'] ?? [];
            $assistant = $contents[1] ?? [];
            $user = $contents[2] ?? [];

            return ($assistant['role'] ?? '') === 'model'
                && ($assistant['parts'][0]['functionCall']['name'] ?? '') === 'get_weather'
                && ($user['role'] ?? '') === 'user'
                // Gemini matches the response to the call by name (plus the id, when it sent one).
                && ($user['parts'][0]['functionResponse']['name'] ?? '') === 'get_weather'
                && ($user['parts'][0]['functionResponse']['response'] ?? []) === ['temp_c' => 12];
        });
    }

    public function test_error_results_are_flagged_per_provider(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        GenAiRequest::with(new AnthropicClient(apiKey: 'k'))
            ->messages([[
                'role' => 'user',
                'content' => [ContentBlock::toolResult('call_1', 'upstream timed out', isError: true, toolName: 'get_weather')],
            ]])
            ->generate();

        Http::assertSent(function (Request $req) {
            $block = $req->data()['messages'][0]['content'][0] ?? [];

            return ($block['is_error'] ?? null) === true
                && ($block['content'] ?? '') === 'upstream timed out';
        });
    }

    public function test_gemini_rejects_a_tool_result_with_no_function_name(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []])]);

        $this->expectException(GenAiFatalException::class);
        $this->expectExceptionMessageMatches('/by function name/');

        (new GeminiClient(apiKey: 'k'))->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::toolResult('call_1', 'sunny')],
        ]]);
    }

    public function test_string_results_are_sent_as_text_on_bedrock(): void
    {
        Http::fake(['*' => Http::response(['output' => ['message' => ['content' => []]]])]);

        (new BedrockClient(apiKey: 'k', modelId: 'm'))->converse('', [[
            'role' => 'user',
            'content' => [ContentBlock::toolResult('call_1', 'sunny', toolName: 'get_weather')],
        ]]);

        Http::assertSent(function (Request $req) {
            $block = $req->data()['messages'][0]['content'][0] ?? [];

            return ($block['toolResult']['content'][0]['text'] ?? '') === 'sunny';
        });
    }

    /**
     * The loop as an application would write it: one prompt, one replayed
     * assistant turn, one tool result — no provider-specific payloads anywhere.
     */
    private function sendLoop(GenAiClient $client): void
    {
        $call = ['id' => 'call_1', 'name' => 'get_weather', 'input' => ['city' => 'Boston']];

        $previous = new GenAiResponse(
            text: '',
            toolCalls: [$call],
            usage: Usage::empty(),
            raw: [],
        );

        GenAiRequest::with($client)
            ->messages([
                ['role' => 'user', 'content' => [ContentBlock::text('Weather in Boston?')]],
                $previous->assistantMessage(),
                ['role' => 'user', 'content' => [ContentBlock::toolResultFor($call, ['temp_c' => 12])]],
            ])
            ->generate();
    }
}
