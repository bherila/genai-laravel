<?php

namespace Bherila\GenAiLaravel;

/**
 * Standardized response returned by GenAiRequest::generate().
 *
 * Decouples application code from provider-specific response shapes — callers
 * read text or toolCalls without knowing which provider produced the response.
 */
final class GenAiResponse
{
    /**
     * @param  string  $text  Concatenated text output from the model.
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolCalls  Tool/function calls made by the model.
     * @param  array<string, mixed>  $raw  Provider-specific raw response (for advanced use / debugging).
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $raw,
    ) {}

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }

    /**
     * Returns the first tool call, or null if the model made no calls.
     *
     * @return array{name: string, input: array<string, mixed>}|null
     */
    public function firstToolCall(): ?array
    {
        return $this->toolCalls[0] ?? null;
    }

    /**
     * Returns the first tool call with the given name, or null if not found.
     *
     * @return array{name: string, input: array<string, mixed>}|null
     */
    public function toolCallByName(string $name): ?array
    {
        foreach ($this->toolCalls as $call) {
            if ($call['name'] === $name) {
                return $call;
            }
        }

        return null;
    }
}
