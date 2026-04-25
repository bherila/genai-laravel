<?php

namespace Bherila\GenAiLaravel;

/**
 * Caller-owned table of model prices, keyed by `(provider, modelId)`.
 *
 * No provider catalog API returns pricing today, so `listModels()` always
 * leaves the cost fields on `ModelInfo` null. PricingBook lets a consuming
 * application supply its own pricing table (hard-coded, loaded from config,
 * or fetched out-of-band) and decorate `ModelInfo` results or compute costs
 * from `Usage` without each call site re-implementing the lookup.
 */
final class PricingBook
{
    /**
     * @param  array<string, array<string, ModelPrice>>  $entries  provider => [modelId => ModelPrice]
     */
    public function __construct(public readonly array $entries = []) {}

    /**
     * Build a PricingBook from a config-style array. Each entry under
     * `genai.pricing.<provider>.<modelId>` is a row with keys
     * `input`, `output`, optional `cache_read`, optional `cache_creation`.
     *
     * @param  array<string, array<string, array{input: float|int, output: float|int, cache_read?: float|int|null, cache_creation?: float|int|null}>>  $config
     */
    public static function fromArray(array $config): self
    {
        $entries = [];
        foreach ($config as $provider => $models) {
            foreach ($models as $modelId => $row) {
                $entries[$provider][$modelId] = new ModelPrice(
                    inputPerMillion: (float) $row['input'],
                    outputPerMillion: (float) $row['output'],
                    cacheReadPerMillion: isset($row['cache_read']) ? (float) $row['cache_read'] : null,
                    cacheCreationPerMillion: isset($row['cache_creation']) ? (float) $row['cache_creation'] : null,
                );
            }
        }

        return new self($entries);
    }

    /**
     * Read `genai.pricing` from Laravel config and build a PricingBook.
     */
    public static function fromConfig(): self
    {
        /** @var array<string, array<string, array{input: float|int, output: float|int, cache_read?: float|int|null, cache_creation?: float|int|null}>> $config */
        $config = (array) config('genai.pricing', []);

        return self::fromArray($config);
    }

    public function priceFor(string $provider, string $modelId): ?ModelPrice
    {
        return $this->entries[$provider][$modelId] ?? null;
    }

    /**
     * Return a copy of $model with cost fields populated when a price is known.
     * Existing non-null cost fields on the input are preserved.
     */
    public function enrich(ModelInfo $model): ModelInfo
    {
        $price = $this->priceFor($model->provider, $model->id);
        if ($price === null) {
            return $model;
        }

        return new ModelInfo(
            id: $model->id,
            name: $model->name,
            provider: $model->provider,
            description: $model->description,
            inputTokenLimit: $model->inputTokenLimit,
            outputTokenLimit: $model->outputTokenLimit,
            inputCostPerMillionTokens: $model->inputCostPerMillionTokens ?? $price->inputPerMillion,
            outputCostPerMillionTokens: $model->outputCostPerMillionTokens ?? $price->outputPerMillion,
            raw: $model->raw,
        );
    }

    /**
     * @param  list<ModelInfo>  $models
     * @return list<ModelInfo>
     */
    public function enrichAll(array $models): array
    {
        return array_map(fn (ModelInfo $m) => $this->enrich($m), $models);
    }

    /**
     * Compute USD cost for a Usage record under this book's price for the
     * given (provider, modelId). Returns null when no price is registered.
     */
    public function estimateCost(Usage $usage, string $provider, string $modelId): ?float
    {
        $price = $this->priceFor($provider, $modelId);
        if ($price === null) {
            return null;
        }

        return $usage->estimatedCostUsd(
            inputPerMillion: $price->inputPerMillion,
            outputPerMillion: $price->outputPerMillion,
            cacheReadPerMillion: $price->cacheReadPerMillion,
            cacheCreationPerMillion: $price->cacheCreationPerMillion,
        );
    }
}
