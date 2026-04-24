<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic description of a model offered by a GenAI provider.
 *
 * Every provider's list-models endpoint uses a different shape (Anthropic:
 * id / display_name; Bedrock: modelId / modelName; Gemini: name / displayName),
 * and none of them return pricing. This value object normalises the identity
 * and capability fields and leaves cost as nullable — callers that track
 * pricing out-of-band can populate the cost fields themselves, or read them as
 * `null` when the provider does not advertise pricing in its catalog.
 */
final class ModelInfo
{
    /**
     * @param  string  $id  The identifier used to call the model (e.g. "claude-sonnet-4-5", "anthropic.claude-3-sonnet-20240229-v1:0", "models/gemini-2.5-flash").
     * @param  string  $name  Human-readable display name.
     * @param  string  $provider  The provider identifier ("anthropic", "bedrock", "gemini").
     * @param  string|null  $description  Free-form description when the provider supplies one.
     * @param  int|null  $inputTokenLimit  Maximum prompt tokens accepted by this model, when known.
     * @param  int|null  $outputTokenLimit  Maximum completion tokens returned by this model, when known.
     * @param  float|null  $inputCostPerMillionTokens  USD per million input tokens, if available from the provider or supplied by the caller.
     * @param  float|null  $outputCostPerMillionTokens  USD per million output tokens, if available.
     * @param  array<string, mixed>  $raw  Provider-specific raw entry, for fields not normalised here.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $provider,
        public readonly ?string $description = null,
        public readonly ?int $inputTokenLimit = null,
        public readonly ?int $outputTokenLimit = null,
        public readonly ?float $inputCostPerMillionTokens = null,
        public readonly ?float $outputCostPerMillionTokens = null,
        public readonly array $raw = [],
    ) {}
}
