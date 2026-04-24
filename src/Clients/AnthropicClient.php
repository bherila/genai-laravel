<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API implementation of GenAiClient.
 *
 * Uses the direct Anthropic API (api.anthropic.com), not AWS Bedrock.
 * Files must be embedded as base64 inline content blocks — uploadFile() returns null.
 *
 * ToolConfig is translated to Anthropic tools + tool_choice format.
 * ContentBlock objects are converted to Anthropic content block format.
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

    /** Anthropic direct API has no persistent File API — always returns null. */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
    {
        return null;
    }

    /** No-op: Anthropic direct API does not store uploaded files. */
    public function deleteFile(string $fileRef): void {}

    /** @throws \LogicException */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null): array
    {
        throw new \LogicException('Anthropic direct API does not support file references. Use converseWithInlineFile() with base64-encoded bytes.');
    }

    /**
     * Send a Messages API request with a single base64-encoded document block.
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, string $system = '', ?ToolConfig $toolConfig = null): array
    {
        $messages = [[
            'role' => 'user',
            'content' => [
                ContentBlock::document($fileBytes, $mimeType),
                ContentBlock::text($prompt),
            ],
        ]];

        return $this->converse($system, $messages, $toolConfig);
    }

    /**
     * @param  list<array{role: string, content: list<ContentBlock>}>  $messages
     */
    public function converse(string $system, array $messages, ?ToolConfig $toolConfig = null): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $this->convertMessages($messages),
        ];

        if ($system !== '') {
            $payload['system'] = [['type' => 'text', 'text' => $system]];
        }

        if ($toolConfig !== null) {
            $native = $this->toolConfigToAnthropic($toolConfig);
            $payload['tools'] = $native['tools'];
            $payload['tool_choice'] = $native['tool_choice'];
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

    /**
     * List models available to this Anthropic API key.
     *
     * Paginates via the `after_id` cursor until `has_more` is false.
     * Anthropic does not return pricing in this endpoint — cost fields are null.
     *
     * @return list<ModelInfo>
     */
    public function listModels(): array
    {
        $models = [];
        $afterId = null;

        do {
            $query = ['limit' => 1000];
            if ($afterId !== null) {
                $query['after_id'] = $afterId;
            }

            $response = $this->http->get(self::API_BASE.'/v1/models', $query);

            if (! $response->successful()) {
                Log::error('Anthropic list models failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                if ($response->status() === 429) {
                    throw new GenAiRateLimitException('Anthropic API rate limit exceeded.');
                }
                if (in_array($response->status(), [400, 401, 403, 404], true)) {
                    throw new GenAiFatalException('Anthropic API error: '.$response->body());
                }
                throw new GenAiException('Anthropic API error '.$response->status().': '.$response->body());
            }

            $payload = $response->json() ?? [];
            foreach ($payload['data'] ?? [] as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $models[] = new ModelInfo(
                    id: $id,
                    name: (string) ($entry['display_name'] ?? $id),
                    provider: 'anthropic',
                    raw: is_array($entry) ? $entry : [],
                );
            }

            $hasMore = (bool) ($payload['has_more'] ?? false);
            $afterId = $hasMore ? ($payload['last_id'] ?? null) : null;
        } while ($afterId !== null);

        return $models;
    }

    /**
     * Extract normalised token usage from an Anthropic Messages API response.
     *
     * Anthropic reports input_tokens as non-cached input (cache_read and
     * cache_creation are separate buckets), so the three input fields are
     * already non-overlapping.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractUsage(array $response): Usage
    {
        $u = $response['usage'] ?? null;
        if (! is_array($u)) {
            return Usage::empty();
        }

        $input = (int) ($u['input_tokens'] ?? 0);
        $output = (int) ($u['output_tokens'] ?? 0);
        $cacheRead = (int) ($u['cache_read_input_tokens'] ?? 0);
        $cacheCreate = (int) ($u['cache_creation_input_tokens'] ?? 0);

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $input + $cacheRead + $cacheCreate + $output,
            cacheReadInputTokens: $cacheRead,
            cacheCreationInputTokens: $cacheCreate,
            raw: $u,
        );
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /** @param  list<array{role: string, content: list<ContentBlock>}>  $messages */
    private function convertMessages(array $messages): array
    {
        return array_map(function (array $msg) {
            return [
                'role' => $msg['role'],
                'content' => array_map(
                    fn (ContentBlock $b) => $this->contentBlockToAnthropic($b),
                    $msg['content'],
                ),
            ];
        }, $messages);
    }

    private function contentBlockToAnthropic(ContentBlock $block): array
    {
        if ($block->type === 'document') {
            return [
                'type' => 'document',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $block->mimeType,
                    'data' => $block->base64,
                ],
            ];
        }

        return ['type' => 'text', 'text' => $block->text ?? ''];
    }

    private function toolConfigToAnthropic(ToolConfig $config): array
    {
        $tools = array_map(fn (ToolDefinition $t) => [
            'name' => $t->name,
            'description' => $t->description,
            'input_schema' => $t->inputSchema->toArray(),
        ], $config->tools);

        $toolChoice = match ($config->choice->type) {
            ToolChoice::ANY => ['type' => 'any'],
            ToolChoice::NONE => ['type' => 'none'],
            ToolChoice::TOOL => ['type' => 'tool', 'name' => $config->choice->toolName],
            default => ['type' => 'auto'],
        };

        return ['tools' => $tools, 'tool_choice' => $toolChoice];
    }
}
