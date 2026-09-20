<?php

namespace Bherila\GenAiLaravel\Mcp;

use DateTimeInterface;

final readonly class EnqueueOptions
{
    /**
     * @param  int|null  $maxAttempts  A per-request override. Null leaves the
     *                                 decision to `genai.mcp.max_attempts`, so
     *                                 passing this object to set a queue,
     *                                 priority, schedule, metadata or
     *                                 idempotency key does not silently pin the
     *                                 attempt ceiling to a default.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $queue = 'default',
        public ?string $idempotencyKey = null,
        public int $priority = 0,
        public ?DateTimeInterface $availableAt = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?int $maxAttempts = null,
        public array $metadata = [],
    ) {}
}
