<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\GenAiRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * A tool loop replays the assistant turn before its results. Rebuilding that turn
 * from the flattened text + toolCalls projections drops two things providers care
 * about — the interleaving of parts, and opaque per-part state such as a Gemini
 * thought signature — and the loss surfaces only as a provider validation error
 * on the *next* request, which a mocked suite asserting our own payloads cannot
 * see. These tests assert the round trip instead.
 */
class AssistantTurnPreservationTest extends TestCase
{
    private function geminiResponse(array $parts): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts]]]];
    }

    /** Replays a Gemini response through a second request and returns the sent history. */
    private function replayGemini(array $parts): array
    {
        $calls = 0;
        Http::fake(['*' => function () use (&$calls, $parts) {
            $calls++;

            return $calls === 1
                ? Http::response($this->geminiResponse($parts))
                : Http::response($this->geminiResponse([['text' => 'done']]));
        }]);

        $client = new GeminiClient(apiKey: 'k');
        $response = GenAiRequest::with($client)->prompt('go')->generate();

        $history = [
            ['role' => 'user', 'content' => [ContentBlock::text('go')]],
            $response->assistantMessage(),
        ];

        GenAiRequest::with($client)->messages($history)->generate();

        $sent = [];
        Http::assertSent(function (Request $req) use (&$sent) {
            $sent[] = $req->data();

            return true;
        });

        return $sent[1]['contents'][1] ?? [];
    }

    // ── 1. a single call with a signature ────────────────────────────────────

    public function test_gemini_thought_signature_survives_one_function_call(): void
    {
        $replayed = $this->replayGemini([[
            'functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Boston']],
            'thoughtSignature' => 'sig-abc',
        ]]);

        $this->assertSame('sig-abc', $replayed['parts'][0]['thoughtSignature'] ?? null);
        $this->assertSame('get_weather', $replayed['parts'][0]['functionCall']['name'] ?? null);
    }

    // ── 2. parallel calls, signature only on the first ───────────────────────

    public function test_gemini_parallel_calls_keep_the_signature_on_its_own_part(): void
    {
        $replayed = $this->replayGemini([
            ['functionCall' => ['name' => 'a', 'args' => []], 'thoughtSignature' => 'sig-1'],
            ['functionCall' => ['name' => 'b', 'args' => []]],
        ]);

        $this->assertSame('sig-1', $replayed['parts'][0]['thoughtSignature'] ?? null);
        $this->assertSame('a', $replayed['parts'][0]['functionCall']['name'] ?? null);
        // The signature must not migrate onto the second call.
        $this->assertArrayNotHasKey('thoughtSignature', $replayed['parts'][1]);
        $this->assertSame('b', $replayed['parts'][1]['functionCall']['name'] ?? null);
    }

    // ── 3. accumulated signatures across parts ───────────────────────────────

    public function test_gemini_keeps_every_accumulated_signature(): void
    {
        $replayed = $this->replayGemini([
            ['functionCall' => ['name' => 'a', 'args' => []], 'thoughtSignature' => 'sig-1'],
            ['functionCall' => ['name' => 'b', 'args' => []], 'thoughtSignature' => 'sig-2'],
        ]);

        $this->assertSame(
            ['sig-1', 'sig-2'],
            array_column($replayed['parts'], 'thoughtSignature'),
        );
    }

    // ── 4. mixed text and calls keep their order ─────────────────────────────

    public function test_gemini_preserves_interleaved_part_order(): void
    {
        $replayed = $this->replayGemini([
            ['text' => 'Let me check.'],
            ['functionCall' => ['name' => 'get_weather', 'args' => []], 'thoughtSignature' => 'sig-mid'],
            ['text' => 'One moment.'],
        ]);

        $parts = $replayed['parts'];

        $this->assertCount(3, $parts);
        $this->assertSame('Let me check.', $parts[0]['text'] ?? null);
        $this->assertSame('get_weather', $parts[1]['functionCall']['name'] ?? null);
        $this->assertSame('sig-mid', $parts[1]['thoughtSignature'] ?? null);
        $this->assertSame('One moment.', $parts[2]['text'] ?? null);
    }

    public function test_gemini_replays_unmodelled_parts_verbatim(): void
    {
        $replayed = $this->replayGemini([
            ['thought' => true, 'text' => null, 'someFutureKey' => ['x' => 1]],
            ['functionCall' => ['name' => 'a', 'args' => []]],
        ]);

        $this->assertSame(['x' => 1], $replayed['parts'][0]['someFutureKey'] ?? null);
    }

    // ── other providers ──────────────────────────────────────────────────────

    public function test_anthropic_preserves_thinking_blocks_and_order(): void
    {
        $calls = 0;
        Http::fake(['*' => function () use (&$calls) {
            $calls++;
            $content = $calls === 1
                ? [
                    ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'think-sig'],
                    ['type' => 'text', 'text' => 'Checking.'],
                    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => []],
                ]
                : [['type' => 'text', 'text' => 'done']];

            return Http::response(['content' => $content, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
        }]);

        $client = new AnthropicClient(apiKey: 'k');
        $response = GenAiRequest::with($client)->prompt('go')->generate();

        GenAiRequest::with($client)
            ->messages([['role' => 'user', 'content' => [ContentBlock::text('go')]], $response->assistantMessage()])
            ->generate();

        $sent = [];
        Http::assertSent(function (Request $req) use (&$sent) {
            $sent[] = $req->data();

            return true;
        });

        $replayed = $sent[1]['messages'][1]['content'];

        $this->assertSame('thinking', $replayed[0]['type'] ?? null);
        $this->assertSame('think-sig', $replayed[0]['signature'] ?? null);
        $this->assertSame('text', $replayed[1]['type'] ?? null);
        $this->assertSame('tool_use', $replayed[2]['type'] ?? null);
        $this->assertSame('toolu_1', $replayed[2]['id'] ?? null);
    }

    public function test_bedrock_preserves_reasoning_blocks_and_order(): void
    {
        $calls = 0;
        Http::fake(['*' => function () use (&$calls) {
            $calls++;
            $content = $calls === 1
                ? [
                    ['reasoningContent' => ['reasoningText' => ['text' => 'hmm', 'signature' => 'r-sig']]],
                    ['text' => 'Checking.'],
                    ['toolUse' => ['toolUseId' => 'tu_1', 'name' => 'get_weather', 'input' => []]],
                ]
                : [['text' => 'done']];

            return Http::response(['output' => ['message' => ['content' => $content]]]);
        }]);

        $client = new BedrockClient(apiKey: 'k', modelId: 'm');
        $response = GenAiRequest::with($client)->prompt('go')->generate();

        GenAiRequest::with($client)
            ->messages([['role' => 'user', 'content' => [ContentBlock::text('go')]], $response->assistantMessage()])
            ->generate();

        $sent = [];
        Http::assertSent(function (Request $req) use (&$sent) {
            $sent[] = $req->data();

            return true;
        });

        $replayed = $sent[1]['messages'][1]['content'];

        $this->assertSame('r-sig', $replayed[0]['reasoningContent']['reasoningText']['signature'] ?? null);
        $this->assertSame('Checking.', $replayed[1]['text'] ?? null);
        $this->assertSame('tu_1', $replayed[2]['toolUse']['toolUseId'] ?? null);
    }
}
