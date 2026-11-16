<?php

namespace Bherila\GenAiLaravel;

/**
 * Per-million-token pricing for a single model. Cache prices are optional and
 * default to the input price when not supplied, matching providers that do not
 * discount cache reads.
 *
 * A model with a long-prompt tier carries a second price, used for the whole
 * request once its prompt passes longPromptThreshold tokens.
 */
final class ModelPrice
{
    public function __construct(
        public readonly float $inputPerMillion,
        public readonly float $outputPerMillion,
        public readonly ?float $cacheReadPerMillion = null,
        public readonly ?float $cacheCreationPerMillion = null,
        public readonly ?int $longPromptThreshold = null,
        public readonly ?ModelPrice $longPromptPrice = null,
    ) {}

    /**
     * The price one request pays, given the size of its prompt.
     *
     * Some models charge a higher rate for every token of a request whose
     * prompt passes a size threshold (Claude Haiku 5.5: over 100,000 tokens,
     * counting cache reads and writes). The prompt is input plus cache-read
     * plus cache-creation tokens.
     */
    public function forPromptTokens(int $promptTokens): self
    {
        if ($this->longPromptThreshold !== null
            && $this->longPromptPrice !== null
            && $promptTokens > $this->longPromptThreshold) {
            return $this->longPromptPrice;
        }

        return $this;
    }
}
