<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic token-usage data from a GenAI response.
 *
 * Every provider reports tokens under a different key (Anthropic: input_tokens /
 * output_tokens; Bedrock: inputTokens / outputTokens; Gemini: promptTokenCount /
 * candidatesTokenCount). This value object normalises them so call sites can read
 * usage without branching on provider.
 *
 * Cost is not returned by any provider API — callers compute it from tokens using
 * estimatedCostUsd() with per-million-token prices for the specific model in use.
 */
final class Usage
{
    /**
     * @param  int  $inputTokens  Prompt / input tokens billed.
     * @param  int  $outputTokens  Completion / output tokens billed.
     * @param  int  $totalTokens  Sum reported by the provider when available, otherwise input + output.
     * @param  int  $cacheReadInputTokens  Input tokens served from prompt cache (0 when unsupported / unused).
     * @param  int  $cacheCreationInputTokens  Input tokens written to prompt cache (0 when unsupported / unused).
     * @param  array<string, mixed>  $raw  Provider-specific raw usage payload, for fields not normalised here.
     */
    public function __construct(
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $totalTokens,
        public readonly int $cacheReadInputTokens = 0,
        public readonly int $cacheCreationInputTokens = 0,
        public readonly array $raw = [],
    ) {}

    /**
     * Zero-usage instance for responses where the provider returned no usage data.
     */
    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * Estimate cost in USD given per-million-token prices for the model.
     *
     * $inputTokens, $cacheReadInputTokens, and $cacheCreationInputTokens are treated as
     * non-overlapping buckets — the Gemini client subtracts cached tokens from the prompt
     * count so this invariant holds across providers. Cache prices default to the base
     * input price when omitted, which matches providers that do not discount cache reads.
     */
    public function estimatedCostUsd(
        float $inputPerMillion,
        float $outputPerMillion,
        ?float $cacheReadPerMillion = null,
        ?float $cacheCreationPerMillion = null,
    ): float {
        $cacheReadPrice = $cacheReadPerMillion ?? $inputPerMillion;
        $cacheCreationPrice = $cacheCreationPerMillion ?? $inputPerMillion;

        return (
            $this->inputTokens * $inputPerMillion
            + $this->outputTokens * $outputPerMillion
            + $this->cacheReadInputTokens * $cacheReadPrice
            + $this->cacheCreationInputTokens * $cacheCreationPrice
        ) / 1_000_000;
    }
}
