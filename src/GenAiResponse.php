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
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls  Tool/function calls made by the model. `id` correlates a result back to its call (empty on providers that match by name).
     * @param  Usage  $usage  Normalised token-usage data.
     * @param  array<string, mixed>  $raw  Provider-specific raw response (for advanced use / debugging).
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly Usage $usage,
        public readonly array $raw,
    ) {}

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }

    /**
     * Returns the first tool call, or null if the model made no calls.
     *
     * @return array{id: string, name: string, input: array<string, mixed>}|null
     */
    public function firstToolCall(): ?array
    {
        return $this->toolCalls[0] ?? null;
    }

    /**
     * Returns the first tool call with the given name, or null if not found.
     *
     * @return array{id: string, name: string, input: array<string, mixed>}|null
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

    /**
     * This turn rebuilt as a message you can append to the conversation.
     *
     * Anthropic and Bedrock both reject a tool result whose matching tool call is
     * not already in the history, so a tool loop needs the assistant turn replayed
     * — and doing that from $raw would mean writing provider-specific code at
     * exactly the call site this package exists to keep provider-agnostic:
     *
     *   $messages[] = ['role' => 'user', 'content' => [ContentBlock::text($prompt)]];
     *   $response   = GenAiRequest::with($client)->messages($messages)->tools($tools)->generate();
     *
     *   while ($response->hasToolCalls()) {
     *       $messages[] = $response->assistantMessage();
     *       $results    = [];
     *       foreach ($response->toolCalls as $call) {
     *           $results[] = ContentBlock::toolResultFor($call, $myTools->run($call['name'], $call['input']));
     *       }
     *       $messages[] = ['role' => 'user', 'content' => $results];
     *       $response   = GenAiRequest::with($client)->messages($messages)->tools($tools)->generate();
     *   }
     *
     * @return array{role: string, content: list<ContentBlock>}
     */
    public function assistantMessage(): array
    {
        $content = [];

        if ($this->text !== '') {
            $content[] = ContentBlock::text($this->text);
        }

        foreach ($this->toolCalls as $call) {
            $content[] = ContentBlock::toolCall(
                id: $call['id'],
                name: $call['name'],
                input: $call['input'],
            );
        }

        return ['role' => 'assistant', 'content' => $content];
    }
}
