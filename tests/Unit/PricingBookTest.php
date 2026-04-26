<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ModelPrice;
use Bherila\GenAiLaravel\PricingBook;
use Bherila\GenAiLaravel\Usage;
use Orchestra\Testbench\TestCase;

class PricingBookTest extends TestCase
{
    public function test_from_array_builds_entries_for_all_three_providers(): void
    {
        $book = PricingBook::fromArray([
            'anthropic' => [
                'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3, 'cache_creation' => 3.75],
            ],
            'bedrock' => [
                'us.anthropic.claude-haiku-4-20250514-v1:0' => ['input' => 0.8, 'output' => 4.0],
            ],
            'gemini' => [
                'gemini-2.0-flash' => ['input' => 0.1, 'output' => 0.4],
            ],
        ]);

        $anthropic = $book->priceFor('anthropic', 'claude-sonnet-4-6');
        $this->assertInstanceOf(ModelPrice::class, $anthropic);
        $this->assertSame(3.0, $anthropic->inputPerMillion);
        $this->assertSame(15.0, $anthropic->outputPerMillion);
        $this->assertSame(0.3, $anthropic->cacheReadPerMillion);
        $this->assertSame(3.75, $anthropic->cacheCreationPerMillion);

        $bedrock = $book->priceFor('bedrock', 'us.anthropic.claude-haiku-4-20250514-v1:0');
        $this->assertSame(0.8, $bedrock->inputPerMillion);
        $this->assertNull($bedrock->cacheReadPerMillion);

        $gemini = $book->priceFor('gemini', 'gemini-2.0-flash');
        $this->assertSame(0.1, $gemini->inputPerMillion);
        $this->assertSame(0.4, $gemini->outputPerMillion);
    }

    public function test_price_for_returns_null_when_unknown(): void
    {
        $book = PricingBook::fromArray([]);
        $this->assertNull($book->priceFor('anthropic', 'nope'));
    }

    public function test_enrich_populates_cost_fields_for_each_provider(): void
    {
        $book = PricingBook::fromArray([
            'anthropic' => ['claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0]],
            'bedrock' => ['us.anthropic.claude-haiku-4-20250514-v1:0' => ['input' => 0.8, 'output' => 4.0]],
            'gemini' => ['gemini-2.0-flash' => ['input' => 0.1, 'output' => 0.4]],
        ]);

        foreach ([
            ['anthropic', 'claude-sonnet-4-6', 3.0, 15.0],
            ['bedrock', 'us.anthropic.claude-haiku-4-20250514-v1:0', 0.8, 4.0],
            ['gemini', 'gemini-2.0-flash', 0.1, 0.4],
        ] as [$provider, $id, $in, $out]) {
            $enriched = $book->enrich(new ModelInfo(id: $id, name: $id, provider: $provider));
            $this->assertSame($in, $enriched->inputCostPerMillionTokens, "$provider input");
            $this->assertSame($out, $enriched->outputCostPerMillionTokens, "$provider output");
        }
    }

    public function test_enrich_preserves_existing_cost_fields(): void
    {
        $book = PricingBook::fromArray([
            'anthropic' => ['claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0]],
        ]);

        $enriched = $book->enrich(new ModelInfo(
            id: 'claude-sonnet-4-6',
            name: 'Claude',
            provider: 'anthropic',
            inputCostPerMillionTokens: 99.0,
        ));

        $this->assertSame(99.0, $enriched->inputCostPerMillionTokens);
        $this->assertSame(15.0, $enriched->outputCostPerMillionTokens);
    }

    public function test_enrich_returns_input_unchanged_when_no_price(): void
    {
        $book = PricingBook::fromArray([]);
        $model = new ModelInfo(id: 'x', name: 'X', provider: 'anthropic');
        $this->assertSame($model, $book->enrich($model));
    }

    public function test_enrich_all_maps_list(): void
    {
        $book = PricingBook::fromArray([
            'gemini' => ['gemini-2.0-flash' => ['input' => 0.1, 'output' => 0.4]],
        ]);

        $out = $book->enrichAll([
            new ModelInfo(id: 'gemini-2.0-flash', name: 'Flash', provider: 'gemini'),
            new ModelInfo(id: 'unknown', name: 'Unknown', provider: 'gemini'),
        ]);

        $this->assertSame(0.1, $out[0]->inputCostPerMillionTokens);
        $this->assertNull($out[1]->inputCostPerMillionTokens);
    }

    public function test_estimate_cost_uses_price_for_provider_and_model(): void
    {
        $book = PricingBook::fromArray([
            'anthropic' => ['claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0, 'cache_read' => 0.3]],
        ]);

        $usage = new Usage(inputTokens: 1_000_000, outputTokens: 500_000, totalTokens: 3_500_000, cacheReadInputTokens: 2_000_000);

        // 1M*3 + 0.5M*15 + 2M*0.3 = 3 + 7.5 + 0.6 = 11.1
        $this->assertSame(11.1, $book->estimateCost($usage, 'anthropic', 'claude-sonnet-4-6'));
    }

    public function test_estimate_cost_returns_null_when_unknown(): void
    {
        $book = PricingBook::fromArray([]);
        $this->assertNull($book->estimateCost(new Usage(1, 1, 2), 'gemini', 'nope'));
    }

    public function test_from_config_reads_genai_pricing(): void
    {
        config()->set('genai.pricing', [
            'bedrock' => ['us.anthropic.claude-haiku-4-20250514-v1:0' => ['input' => 0.8, 'output' => 4.0]],
        ]);

        $book = PricingBook::fromConfig();
        $price = $book->priceFor('bedrock', 'us.anthropic.claude-haiku-4-20250514-v1:0');

        $this->assertSame(0.8, $price->inputPerMillion);
        $this->assertSame(4.0, $price->outputPerMillion);
    }
}
