<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API implementation of GenAiClient.
 *
 * Uses the direct Anthropic API (api.anthropic.com), not AWS Bedrock.
 * Files must be embedded as base64 inline content blocks — there is no
 * persistent File API. Use converseWithInlineFile() to send documents.
 *
 * Config keys (all under genai.providers.anthropic):
 *   api_key    — Anthropic API key
 *   model      — e.g. "claude-sonnet-4-6" (default: "claude-sonnet-4-6")
 *   max_tokens — maximum output tokens (default: 8192)
 *   timeout    — HTTP timeout in seconds (default: 240)
 */
class AnthropicClient implements GenAiClient
{
    private const API_BASE = 'https://api.anthropic.com';

    private const API_VERSION = '2023-06-01';

    private string $model;

    private int $maxTokens;

    private \Illuminate\Http\Client\PendingRequest $http;

    public function __construct(
        string $apiKey,
        string $model = 'claude-sonnet-4-6',
        int $maxTokens = 8192,
        int $timeout = 240,
    ) {
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->http = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ])->timeout($timeout);
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    /**
     * Anthropic API limit per base64 document block.
     * https://docs.anthropic.com/en/docs/build-with-claude/files
     */
    public static function maxFileBytes(): int
    {
        return 4_718_592; // 4.5 MB
    }

    /**
     * Anthropic direct API has no persistent File API — always returns null.
     * Use converseWithInlineFile() to send documents.
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
    {
        return null;
    }

    /**
     * No-op: Anthropic direct API does not store uploaded files.
     */
    public function deleteFile(string $fileRef): void {}

    /**
     * Not applicable for Anthropic direct API — use converseWithInlineFile() instead.
     *
     * @throws \LogicException
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?array $toolConfig = null): array
    {
        throw new \LogicException('Anthropic direct API does not support file references. Use converseWithInlineFile() with base64-encoded bytes.');
    }

    /**
     * Send a Messages API request with a single base64-encoded document block.
     *
     * @param  list<array{text: string}>  $system
     * @param  array<string, mixed>|null  $toolConfig  Anthropic toolConfig shape: {tools: [...], tool_choice: {...}}.
     * @return array<string, mixed>
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, array $system = [], ?array $toolConfig = null): array
    {
        $messages = [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mimeType,
                        'data' => $fileBytes,
                    ],
                ],
                ['type' => 'text', 'text' => $prompt],
            ],
        ]];

        return $this->converse($system, $messages, $toolConfig);
    }

    /**
     * @param  list<array{text: string}>  $system
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages  Anthropic-format content blocks.
     * @param  array<string, mixed>|null  $toolConfig  Shape: {tools: [...], tool_choice: {...}}.
     * @return array<string, mixed>
     */
    public function converse(array $system, array $messages, ?array $toolConfig = null): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages,
        ];

        if ($system !== []) {
            $payload['system'] = array_map(
                fn ($block) => ['type' => 'text', 'text' => $block['text'] ?? ''],
                $system,
            );
        }

        if ($toolConfig !== null && $toolConfig !== []) {
            if (isset($toolConfig['tools'])) {
                $payload['tools'] = $toolConfig['tools'];
            }
            if (isset($toolConfig['tool_choice'])) {
                $payload['tool_choice'] = $toolConfig['tool_choice'];
            }
        }

        $response = $this->http->post(self::API_BASE.'/v1/messages', $payload);

        if (! $response->successful()) {
            Log::error('Anthropic API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 429) {
                throw new GenAiRateLimitException('Anthropic API rate limit exceeded.');
            }

            if (in_array($response->status(), [400, 403, 404], true)) {
                throw new GenAiFatalException('Anthropic API error: '.$response->body());
            }

            throw new GenAiException('Anthropic API error '.$response->status().': '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Extract text content from an Anthropic Messages API response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractText(array $response): string
    {
        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Extract tool_use blocks from an Anthropic Messages API response.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public function extractToolCalls(array $response): array
    {
        $calls = [];
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') !== 'tool_use') {
                continue;
            }
            $calls[] = [
                'name' => (string) ($block['name'] ?? ''),
                'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
            ];
        }

        return $calls;
    }
}
