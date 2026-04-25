<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\ModelInfo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class ListModelsTest extends TestCase
{
    // ── Anthropic ────────────────────────────────────────────────────────────

    public function test_anthropic_list_models_normalises_entries(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/models*' => Http::response([
                'data' => [
                    ['type' => 'model', 'id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'created_at' => '2025-09-01T00:00:00Z'],
                    ['type' => 'model', 'id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5', 'created_at' => '2025-10-01T00:00:00Z'],
                ],
                'has_more' => false,
            ]),
        ]);

        $client = new AnthropicClient(apiKey: 'test-key', model: 'claude-sonnet-4-6');
        $models = $client->listModels();

        $this->assertCount(2, $models);
        $this->assertInstanceOf(ModelInfo::class, $models[0]);
        $this->assertSame('claude-sonnet-4-6', $models[0]->id);
        $this->assertSame('Claude Sonnet 4.6', $models[0]->name);
        $this->assertSame('anthropic', $models[0]->provider);
        $this->assertNull($models[0]->inputCostPerMillionTokens);
        $this->assertSame('2025-09-01T00:00:00Z', $models[0]->raw['created_at']);
    }

    public function test_anthropic_list_models_sends_api_key_and_version(): void
    {
        Http::fake(['*' => Http::response(['data' => [], 'has_more' => false])]);

        (new AnthropicClient(apiKey: 'test-key', model: 'claude-sonnet-4-6'))->listModels();

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/v1/models')
                && $req->header('x-api-key')[0] === 'test-key'
                && $req->header('anthropic-version')[0] === '2023-06-01';
        });
    }

    public function test_anthropic_list_models_paginates(): void
    {
        $callCount = 0;
        Http::fake([
            'https://api.anthropic.com/v1/models*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response([
                        'data' => [['type' => 'model', 'id' => 'model-a', 'display_name' => 'A']],
                        'has_more' => true,
                        'last_id' => 'model-a',
                    ]);
                }

                return Http::response([
                    'data' => [['type' => 'model', 'id' => 'model-b', 'display_name' => 'B']],
                    'has_more' => false,
                ]);
            },
        ]);

        $models = (new AnthropicClient(apiKey: 'test-key', model: 'claude-sonnet-4-6'))->listModels();

        $this->assertCount(2, $models);
        $this->assertSame('model-a', $models[0]->id);
        $this->assertSame('model-b', $models[1]->id);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'after_id=model-a'));
    }

    public function test_anthropic_list_models_throws_on_auth_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'bad key'], 401)]);

        $this->expectException(GenAiFatalException::class);
        (new AnthropicClient(apiKey: 'test-key', model: 'claude-sonnet-4-6'))->listModels();
    }

    // ── Bedrock ──────────────────────────────────────────────────────────────

    public function test_bedrock_list_models_hits_control_plane_endpoint(): void
    {
        Http::fake([
            'https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response([
                'modelSummaries' => [
                    [
                        'modelArn' => 'arn:aws:bedrock:us-east-1::foundation-model/anthropic.claude-3-sonnet-20240229-v1:0',
                        'modelId' => 'anthropic.claude-3-sonnet-20240229-v1:0',
                        'modelName' => 'Claude 3 Sonnet',
                        'providerName' => 'Anthropic',
                        'modelLifecycle' => ['status' => 'ACTIVE'],
                    ],
                    [
                        'modelId' => 'meta.llama3-8b-instruct-v1:0',
                        'modelName' => 'Llama 3 8B Instruct',
                        'providerName' => 'Meta',
                    ],
                ],
            ]),
            'https://bedrock.us-east-1.amazonaws.com/inference-profiles' => Http::response([
                'inferenceProfileSummaries' => [],
            ]),
        ]);

        $client = new BedrockClient(apiKey: 'test', modelId: 'anthropic.claude-3-sonnet-20240229-v1:0');
        $models = $client->listModels();

        $this->assertCount(2, $models);
        $this->assertSame('anthropic.claude-3-sonnet-20240229-v1:0', $models[0]->id);
        $this->assertSame('Claude 3 Sonnet', $models[0]->name);
        $this->assertSame('bedrock', $models[0]->provider);
        $this->assertSame('Provider: Anthropic', $models[0]->description);
        $this->assertSame('Meta', $models[1]->raw['providerName']);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://bedrock.us-east-1.amazonaws.com/foundation-models');
        Http::assertSent(fn (Request $req) => $req->url() === 'https://bedrock.us-east-1.amazonaws.com/inference-profiles');
    }

    public function test_bedrock_list_models_includes_inference_profiles(): void
    {
        Http::fake([
            'https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response(['modelSummaries' => []]),
            'https://bedrock.us-east-1.amazonaws.com/inference-profiles' => Http::response([
                'inferenceProfileSummaries' => [
                    [
                        'inferenceProfileId' => 'us.anthropic.claude-haiku-4-20250514-v1:0',
                        'inferenceProfileName' => 'Cross-region Anthropic Claude Haiku 4',
                        'inferenceProfileArn' => 'arn:aws:bedrock:us-east-1::inference-profile/us.anthropic.claude-haiku-4-20250514-v1:0',
                        'type' => 'SYSTEM_DEFINED',
                        'status' => 'ACTIVE',
                        'description' => 'Routes across US regions',
                    ],
                ],
            ]),
        ]);

        $models = (new BedrockClient(apiKey: 'test', modelId: 'any'))->listModels();

        $this->assertCount(1, $models);
        $this->assertSame('us.anthropic.claude-haiku-4-20250514-v1:0', $models[0]->id);
        $this->assertSame('Cross-region Anthropic Claude Haiku 4', $models[0]->name);
        $this->assertSame('bedrock', $models[0]->provider);
        $this->assertSame('Routes across US regions', $models[0]->description);
        $this->assertSame('SYSTEM_DEFINED', $models[0]->raw['type']);
    }

    public function test_bedrock_list_models_paginates_inference_profiles(): void
    {
        $callCount = 0;
        Http::fake([
            'https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response(['modelSummaries' => []]),
            'https://bedrock.us-east-1.amazonaws.com/inference-profiles*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response([
                        'inferenceProfileSummaries' => [[
                            'inferenceProfileId' => 'us.anthropic.claude-sonnet-4-6',
                            'inferenceProfileName' => 'Profile A',
                        ]],
                        'nextToken' => 'page-2-token',
                    ]);
                }

                return Http::response([
                    'inferenceProfileSummaries' => [[
                        'inferenceProfileId' => 'eu.anthropic.claude-sonnet-4-6',
                        'inferenceProfileName' => 'Profile B',
                    ]],
                ]);
            },
        ]);

        $models = (new BedrockClient(apiKey: 'test', modelId: 'any'))->listModels();

        $this->assertCount(2, $models);
        $this->assertSame('us.anthropic.claude-sonnet-4-6', $models[0]->id);
        $this->assertSame('eu.anthropic.claude-sonnet-4-6', $models[1]->id);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'nextToken=page-2-token'));
    }

    public function test_bedrock_list_models_uses_configured_region(): void
    {
        Http::fake(['*' => Http::response(['modelSummaries' => [], 'inferenceProfileSummaries' => []])]);

        (new BedrockClient(apiKey: 'test', modelId: 'anything', region: 'eu-west-1'))->listModels();

        Http::assertSent(fn (Request $req) => str_starts_with($req->url(), 'https://bedrock.eu-west-1.amazonaws.com/'));
    }

    public function test_bedrock_list_models_throws_on_auth_error(): void
    {
        Http::fake(['*' => Http::response(['message' => 'forbidden'], 403)]);

        $this->expectException(GenAiFatalException::class);
        (new BedrockClient(apiKey: 'test', modelId: 'any'))->listModels();
    }

    public function test_bedrock_list_models_retries_on_429_and_succeeds(): void
    {
        $callCount = 0;
        Http::fake([
            'https://bedrock.us-east-1.amazonaws.com/foundation-models' => function () use (&$callCount) {
                $callCount++;

                return $callCount === 1
                    ? Http::response([], 429, ['Retry-After' => '0'])
                    : Http::response(['modelSummaries' => [['modelId' => 'found-after-retry', 'modelName' => 'M']]]);
            },
            'https://bedrock.us-east-1.amazonaws.com/inference-profiles' => Http::response(['inferenceProfileSummaries' => []]),
        ]);

        $models = (new BedrockClient(apiKey: 'test', modelId: 'any'))->listModels();

        $this->assertCount(1, $models);
        $this->assertSame('found-after-retry', $models[0]->id);
        $this->assertSame(2, $callCount);
    }

    public function test_bedrock_list_models_throws_after_max_retry_attempts(): void
    {
        Http::fake([
            'https://bedrock.us-east-1.amazonaws.com/foundation-models' => Http::response([], 429, ['Retry-After' => '0']),
        ]);

        $this->expectException(\Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException::class);
        (new BedrockClient(apiKey: 'test', modelId: 'any'))->listModels();
    }

    public function test_bedrock_list_models_exposes_retry_after_on_exception(): void
    {
        // First two calls (retried) use Retry-After: 0 to avoid sleeping; third exhausts budget with Retry-After: 42.
        $callCount = 0;
        Http::fake([
            'https://bedrock.us-east-1.amazonaws.com/foundation-models' => function () use (&$callCount) {
                $callCount++;
                $header = $callCount < 3 ? '0' : '42';

                return Http::response([], 429, ['Retry-After' => $header]);
            },
        ]);

        try {
            (new BedrockClient(apiKey: 'test', modelId: 'any'))->listModels();
            $this->fail('Expected GenAiRateLimitException');
        } catch (\Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException $e) {
            $this->assertSame(42, $e->retryAfter);
            $this->assertSame(3, $callCount);
        }
    }

    // ── Gemini ───────────────────────────────────────────────────────────────

    public function test_gemini_list_models_normalises_entries(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models*' => Http::response([
                'models' => [
                    [
                        'name' => 'models/gemini-2.5-flash',
                        'displayName' => 'Gemini 2.5 Flash',
                        'description' => 'Fast and cheap',
                        'inputTokenLimit' => 1_048_576,
                        'outputTokenLimit' => 8192,
                        'supportedGenerationMethods' => ['generateContent', 'countTokens'],
                    ],
                    [
                        'name' => 'models/embedding-001',
                        'displayName' => 'Embedding 001',
                        'supportedGenerationMethods' => ['embedContent'],
                    ],
                ],
            ]),
        ]);

        $models = (new GeminiClient(apiKey: 'test'))->listModels();

        // embedding model is filtered out — can't be used via generateContent.
        $this->assertCount(1, $models);
        $this->assertSame('models/gemini-2.5-flash', $models[0]->id);
        $this->assertSame('Gemini 2.5 Flash', $models[0]->name);
        $this->assertSame('gemini', $models[0]->provider);
        $this->assertSame('Fast and cheap', $models[0]->description);
        $this->assertSame(1_048_576, $models[0]->inputTokenLimit);
        $this->assertSame(8192, $models[0]->outputTokenLimit);
    }

    public function test_gemini_list_models_sends_api_key_header(): void
    {
        Http::fake(['*' => Http::response(['models' => []])]);

        (new GeminiClient(apiKey: 'test-key'))->listModels();

        Http::assertSent(function (Request $req) {
            return str_contains($req->url(), '/v1beta/models')
                && $req->header('x-goog-api-key')[0] === 'test-key';
        });
    }

    public function test_gemini_list_models_paginates(): void
    {
        $callCount = 0;
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models*' => function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return Http::response([
                        'models' => [[
                            'name' => 'models/a',
                            'displayName' => 'A',
                            'supportedGenerationMethods' => ['generateContent'],
                        ]],
                        'nextPageToken' => 'token-2',
                    ]);
                }

                return Http::response([
                    'models' => [[
                        'name' => 'models/b',
                        'displayName' => 'B',
                        'supportedGenerationMethods' => ['generateContent'],
                    ]],
                ]);
            },
        ]);

        $models = (new GeminiClient(apiKey: 'test'))->listModels();

        $this->assertCount(2, $models);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'pageToken=token-2'));
    }

    public function test_gemini_list_models_throws_on_auth_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid key']], 403)]);

        $this->expectException(GenAiFatalException::class);
        (new GeminiClient(apiKey: 'test'))->listModels();
    }

    // ── ModelInfo value object ───────────────────────────────────────────────

    public function test_model_info_exposes_cost_fields_when_supplied(): void
    {
        $info = new ModelInfo(
            id: 'claude-sonnet-4-6',
            name: 'Claude Sonnet 4.6',
            provider: 'anthropic',
            inputCostPerMillionTokens: 3.0,
            outputCostPerMillionTokens: 15.0,
        );

        $this->assertSame(3.0, $info->inputCostPerMillionTokens);
        $this->assertSame(15.0, $info->outputCostPerMillionTokens);
    }
}
