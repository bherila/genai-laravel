<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AWS Bedrock Converse API implementation of GenAiClient.
 *
 * Bedrock does not have a separate File API — files must be embedded as base64
 * inline document blocks. uploadFile() and deleteFile() are therefore no-ops.
 * Use converseWithInlineFile() to send documents.
 *
 * Supports session tokens for temporary IAM credentials.
 *
 * Config keys (all under genai.providers.bedrock):
 *   api_key        — AWS access key ID
 *   secret_key     — AWS secret access key (passed as Bearer token)
 *   session_token  — optional STS session token
 *   region         — e.g. "us-east-1" (default: "us-east-1")
 *   model          — model ID, e.g. "us.anthropic.claude-haiku-4-20250514-v1:0"
 */
class BedrockClient implements GenAiClient
{
    private string $modelId;

    private string $endpoint;

    private \Illuminate\Http\Client\PendingRequest $http;

    /**
     * @param  string  $apiKey      AWS access key ID (used as Bearer token for Bedrock).
     * @param  string  $modelId     Full Bedrock model ID or inference profile ARN.
     * @param  string  $region      AWS region.
     * @param  string  $sessionToken  Optional STS session token for temporary credentials.
     */
    public function __construct(
        string $apiKey,
        string $modelId,
        string $region = 'us-east-1',
        string $sessionToken = '',
    ) {
        $this->modelId = $modelId;
        $this->endpoint = "https://bedrock-runtime.{$region}.amazonaws.com";

        $headers = ['Content-Type' => 'application/json'];
        if ($sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $sessionToken;
        }

        $this->http = Http::withToken($apiKey)->withHeaders($headers);
    }

    public function provider(): string
    {
        return 'bedrock';
    }

    /**
     * Bedrock Converse API hard limit per document block.
     * https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-supported-models-features.html
     */
    public static function maxFileBytes(): int
    {
        return 4_718_592; // 4.5 MB
    }

    /**
     * Bedrock has no File API — always returns null.
     * Use converseWithInlineFile() to send documents.
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
    {
        return null;
    }

    /**
     * No-op: Bedrock does not store uploaded files.
     */
    public function deleteFile(string $fileRef): void {}

    /**
     * Not applicable for Bedrock — use converseWithInlineFile() instead.
     *
     * @throws \LogicException
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?array $toolConfig = null): array
    {
        throw new \LogicException('Bedrock does not support file references. Use converseWithInlineFile() with base64-encoded bytes.');
    }

    /**
     * Send a Converse API request with a single base64-encoded document block.
     *
     * @param  list<array{text: string}>  $system
     * @param  array<string, mixed>|null  $toolConfig  Bedrock toolConfig shape.
     * @return array<string, mixed>
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, array $system = [], ?array $toolConfig = null): array
    {
        $messages = [[
            'role' => 'user',
            'content' => [
                [
                    'document' => [
                        'format' => $this->mimeToFormat($mimeType),
                        'name' => 'document',
                        'source' => ['bytes' => $fileBytes],
                    ],
                ],
                ['text' => $prompt],
            ],
        ]];

        return $this->converse($system, $messages, $toolConfig ?? []);
    }

    /**
     * @param  list<array{text: string}>  $system
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @param  array<string, mixed>|null  $toolConfig
     * @return array<string, mixed>
     */
    public function converse(array $system, array $messages, ?array $toolConfig = null): array
    {
        $payload = [
            'system' => $system,
            'messages' => $messages,
        ];

        if ($toolConfig !== null && $toolConfig !== []) {
            $payload['toolConfig'] = $toolConfig;
        }

        $response = $this->http
            ->post("{$this->endpoint}/model/{$this->modelId}/converse", $payload);

        if (! $response->successful()) {
            Log::error('Bedrock Converse failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 429) {
                throw new GenAiRateLimitException('Bedrock rate limit exceeded.');
            }

            if ($response->status() === 400) {
                throw new GenAiFatalException('Bedrock bad request: '.$response->body());
            }

            throw new GenAiException('Bedrock API error '.$response->status().': '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Extract text content from a Bedrock Converse response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractText(array $response): string
    {
        $content = $response['output']['message']['content'] ?? [];
        $text = '';
        foreach ($content as $block) {
            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Extract tool use blocks from a Bedrock Converse response.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public function extractToolCalls(array $response): array
    {
        $calls = [];
        $content = $response['output']['message']['content'] ?? [];

        foreach ($content as $block) {
            if (! isset($block['toolUse']['name'])) {
                continue;
            }
            $calls[] = [
                'name' => (string) $block['toolUse']['name'],
                'input' => is_array($block['toolUse']['input']) ? $block['toolUse']['input'] : [],
            ];
        }

        return $calls;
    }

    /**
     * Map a MIME type to the Bedrock document format string.
     */
    private function mimeToFormat(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'text/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/html' => 'html',
            'text/plain' => 'txt',
            'text/markdown' => 'md',
            default => 'pdf',
        };
    }
}
