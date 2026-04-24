<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Clients\AnthropicClient;
use Bherila\GenAiLaravel\Clients\BedrockClient;
use Bherila\GenAiLaravel\Clients\GeminiClient;
use Bherila\GenAiLaravel\Usage;
use Orchestra\Testbench\TestCase;

class UsageTest extends TestCase
{
    public function test_anthropic_extracts_input_output_tokens(): void
    {
        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');

        $usage = $client->extractUsage([
            'usage' => ['input_tokens' => 123, 'output_tokens' => 45],
        ]);

        $this->assertSame(123, $usage->inputTokens);
        $this->assertSame(45, $usage->outputTokens);
        $this->assertSame(168, $usage->totalTokens);
        $this->assertSame(0, $usage->cacheReadInputTokens);
        $this->assertSame(0, $usage->cacheCreationInputTokens);
    }

    public function test_anthropic_extracts_cache_tokens(): void
    {
        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');

        $usage = $client->extractUsage([
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'cache_read_input_tokens' => 500,
                'cache_creation_input_tokens' => 50,
            ],
        ]);

        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(20, $usage->outputTokens);
        $this->assertSame(500, $usage->cacheReadInputTokens);
        $this->assertSame(50, $usage->cacheCreationInputTokens);
        $this->assertSame(670, $usage->totalTokens);
    }

    public function test_anthropic_missing_usage_returns_empty(): void
    {
        $client = new AnthropicClient(apiKey: 'test', model: 'claude-sonnet-4-6');

        $usage = $client->extractUsage(['content' => []]);

        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertSame(0, $usage->totalTokens);
    }

    public function test_bedrock_extracts_input_output_tokens(): void
    {
        $client = new BedrockClient(apiKey: 'test', modelId: 'some-model');

        $usage = $client->extractUsage([
            'usage' => ['inputTokens' => 200, 'outputTokens' => 80, 'totalTokens' => 280],
        ]);

        $this->assertSame(200, $usage->inputTokens);
        $this->assertSame(80, $usage->outputTokens);
        $this->assertSame(280, $usage->totalTokens);
    }

    public function test_bedrock_extracts_cache_tokens(): void
    {
        $client = new BedrockClient(apiKey: 'test', modelId: 'some-model');

        $usage = $client->extractUsage([
            'usage' => [
                'inputTokens' => 50,
                'outputTokens' => 10,
                'cacheReadInputTokens' => 300,
                'cacheWriteInputTokens' => 20,
                'totalTokens' => 380,
            ],
        ]);

        $this->assertSame(50, $usage->inputTokens);
        $this->assertSame(10, $usage->outputTokens);
        $this->assertSame(300, $usage->cacheReadInputTokens);
        $this->assertSame(20, $usage->cacheCreationInputTokens);
        $this->assertSame(380, $usage->totalTokens);
    }

    public function test_bedrock_missing_total_is_derived(): void
    {
        $client = new BedrockClient(apiKey: 'test', modelId: 'some-model');

        $usage = $client->extractUsage([
            'usage' => ['inputTokens' => 10, 'outputTokens' => 7],
        ]);

        $this->assertSame(17, $usage->totalTokens);
    }

    public function test_gemini_extracts_tokens_and_subtracts_cache(): void
    {
        $client = new GeminiClient(apiKey: 'test');

        $usage = $client->extractUsage([
            'usageMetadata' => [
                'promptTokenCount' => 300,
                'candidatesTokenCount' => 90,
                'cachedContentTokenCount' => 200,
                'totalTokenCount' => 390,
            ],
        ]);

        // promptTokenCount is inclusive of cache; inputTokens should be non-cached only.
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(90, $usage->outputTokens);
        $this->assertSame(200, $usage->cacheReadInputTokens);
        $this->assertSame(0, $usage->cacheCreationInputTokens);
        $this->assertSame(390, $usage->totalTokens);
    }

    public function test_gemini_missing_metadata_returns_empty(): void
    {
        $client = new GeminiClient(apiKey: 'test');

        $usage = $client->extractUsage(['candidates' => []]);

        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->totalTokens);
    }

    public function test_estimated_cost_usd_base_pricing(): void
    {
        $usage = new Usage(
            inputTokens: 1_000_000,
            outputTokens: 500_000,
            totalTokens: 1_500_000,
        );

        // $3/M input + $15/M output = 3 + 7.5 = 10.5
        $this->assertEqualsWithDelta(10.5, $usage->estimatedCostUsd(3.0, 15.0), 1e-9);
    }

    public function test_estimated_cost_usd_with_cache_pricing(): void
    {
        $usage = new Usage(
            inputTokens: 1_000_000,
            outputTokens: 0,
            totalTokens: 2_050_000,
            cacheReadInputTokens: 1_000_000,
            cacheCreationInputTokens: 50_000,
        );

        // input: 1M * $3 = 3; cacheRead: 1M * $0.30 = 0.30; cacheCreation: 50k * $3.75 = 0.1875
        $cost = $usage->estimatedCostUsd(
            inputPerMillion: 3.0,
            outputPerMillion: 15.0,
            cacheReadPerMillion: 0.30,
            cacheCreationPerMillion: 3.75,
        );

        $this->assertEqualsWithDelta(3.4875, $cost, 1e-9);
    }

    public function test_estimated_cost_defaults_cache_to_input_price(): void
    {
        $usage = new Usage(
            inputTokens: 0,
            outputTokens: 0,
            totalTokens: 1_000_000,
            cacheReadInputTokens: 1_000_000,
        );

        // No override → cache reads billed at input price ($3/M).
        $this->assertEqualsWithDelta(3.0, $usage->estimatedCostUsd(3.0, 15.0), 1e-9);
    }
}
