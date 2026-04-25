<?php

namespace Bherila\GenAiLaravel;

/**
 * Per-million-token pricing for a single model. Cache prices are optional and
 * default to the input price when not supplied, matching providers that do not
 * discount cache reads.
 */
final class ModelPrice
{
    public function __construct(
        public readonly float $inputPerMillion,
        public readonly float $outputPerMillion,
        public readonly ?float $cacheReadPerMillion = null,
        public readonly ?float $cacheCreationPerMillion = null,
    ) {}
}
