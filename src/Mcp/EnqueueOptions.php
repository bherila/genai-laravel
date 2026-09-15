<?php

namespace Bherila\GenAiLaravel\Mcp;

use DateTimeInterface;

final readonly class EnqueueOptions
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $queue = 'default',
        public ?string $idempotencyKey = null,
        public int $priority = 0,
        public ?DateTimeInterface $availableAt = null,
        public ?DateTimeInterface $expiresAt = null,
        public int $maxAttempts = 3,
        public array $metadata = [],
    ) {}
}
