<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Http\RetryStrategy;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * AWS Bedrock Converse API implementation of GenAiClient.
 *
 * Bedrock does not have a separate File API — files must be embedded as base64
 * inline document blocks. uploadFile() returns null and deleteFile() is a no-op.
 *
 * ToolConfig is translated to Bedrock toolSpec + toolChoice format.
 * ContentBlock objects are converted to Bedrock content block format.
 *
 * Auth: this package uses Bearer-token auth (`Authorization: Bearer {api_key}`),
 * not AWS Signature V4. `api_key` is the bearer token itself — not an AWS access
 * key ID. Temporary credentials may add an STS session token.
 *
 * Config keys (all under genai.providers.bedrock):
 *   api_key        — Bearer token used for the Authorization header
 *   session_token  — optional; sent as X-Amz-Security-Token header
 *   region         — AWS region, e.g. "us-east-1" (default: "us-east-1")
 *   model          — model ID, e.g. "us.anthropic.claude-haiku-4-20250514-v1:0"
 *   timeout        — HTTP timeout in seconds (default: 240)
 */
class BedrockClient implements GenAiClient
{
    /** Hard ceiling on `/inference-profiles` pages — defense in depth. */
    private const MAX_INFERENCE_PROFILE_PAGES = 50;

    private string $modelId;

    private string $region;

    private string $endpoint;

    private PendingRequest $http;

    private RetryStrategy $retry;

    public function __construct(
        string $apiKey,
        string $modelId,
        string $region = 'us-east-1',
        string $sessionToken = '',
        ?RetryStrategy $retry = null,
        int $timeout = 240,
    ) {
        $this->modelId = $modelId;
        $this->region = $region;
        $this->endpoint = "https://bedrock-runtime.{$region}.amazonaws.com";

        $headers = ['Content-Type' => 'application/json'];
        if ($sessionToken !== '') {
            $headers['X-Amz-Security-Token'] = $sessionToken;
        }

        $this->http = Http::withToken($apiKey)->withHeaders($headers)->timeout($timeout);
        $this->retry = $retry ?? RetryStrategy::fromConfig();
    }

    public function provider(): string
    {
        return 'bedrock';
    }

    public function model(): string
    {
        return $this->modelId;
    }

    /**
     * Bedrock Converse API hard limit per document block.
     * https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-supported-models-features.html
     */
    public static function maxFileBytes(): int
    {
        return 4_718_592; // 4.5 MB
    }

    /** Bedrock has no File API — always returns null. */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
    {
        return null;
    }

    /** No-op: Bedrock does not store uploaded files. */
    public function deleteFile(string $fileRef): void {}

    /** @throws \LogicException */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null): array
    {
        throw new \LogicException('Bedrock does not support file references. Use converseWithInlineFile() with base64-encoded bytes.');
    }

    /**
     * Send a Converse API request with a single base64-encoded document block.
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
            'messages' => $this->convertMessages($messages),
        ];

        if ($system !== '') {
            $payload['system'] = [['text' => $system]];
        }

        if ($toolConfig !== null) {
            $payload['toolConfig'] = $this->toolConfigToBedrock($toolConfig);
        }

        $response = $this->retry->execute(
            fn () => $this->http->post("{$this->endpoint}/model/{$this->modelId}/converse", $payload),
            'Bedrock Converse',
        );

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

    public function checkCredentials(): bool
    {
        $response = $this->http->get("https://bedrock.{$this->region}.amazonaws.com/foundation-models");
        if ($response->successful()) {
            return true;
        }
        if (in_array($response->status(), [401, 403], true)) {
            return false;
        }
        throw new GenAiFatalException('checkCredentials error '.$response->status().': '.$response->body());
    }

    /**
     * Filter by ModelInfo::$raw['providerName'] or ModelInfo::$raw['type'] to
     * narrow to a specific provider or profile type (SYSTEM_DEFINED / APPLICATION).
     *
     * @return list<ModelInfo>
     */
    public function listModels(): array
    {
        $baseUrl = "https://bedrock.{$this->region}.amazonaws.com";
        $models = [];

        // Foundation models — single page, no pagination.
        $payload = $this->retry->execute(
            fn () => $this->http->get("{$baseUrl}/foundation-models"),
            'Bedrock list foundation-models',
        )->json() ?? [];

        foreach ($payload['modelSummaries'] ?? [] as $entry) {
            $id = (string) ($entry['modelId'] ?? '');
            if ($id === '') {
                continue;
            }
            $provider = $entry['providerName'] ?? null;
            $models[] = new ModelInfo(
                id: $id,
                name: (string) ($entry['modelName'] ?? $id),
                provider: 'bedrock',
                description: is_string($provider) && $provider !== '' ? "Provider: {$provider}" : null,
                raw: is_array($entry) ? $entry : [],
            );
        }

        // Inference profiles — paginated; includes cross-region profiles missing from /foundation-models.
        $nextToken = null;
        $page = 0;
        do {
            $params = $nextToken !== null ? ['nextToken' => $nextToken] : [];
            $payload = $this->retry->execute(
                fn () => $this->http->get("{$baseUrl}/inference-profiles", $params),
                'Bedrock list inference-profiles',
            )->json() ?? [];

            foreach ($payload['inferenceProfileSummaries'] ?? [] as $entry) {
                $id = (string) ($entry['inferenceProfileId'] ?? '');
                if ($id === '') {
                    continue;
                }
                $models[] = new ModelInfo(
                    id: $id,
                    name: (string) ($entry['inferenceProfileName'] ?? $id),
                    provider: 'bedrock',
                    description: isset($entry['description']) && $entry['description'] !== '' ? (string) $entry['description'] : null,
                    raw: is_array($entry) ? $entry : [],
                );
            }
            $nextToken = isset($payload['nextToken']) && is_string($payload['nextToken']) ? $payload['nextToken'] : null;
            $page++;
        } while ($nextToken !== null && $page < self::MAX_INFERENCE_PROFILE_PAGES);

        if ($nextToken !== null) {
            throw new GenAiFatalException(
                'Bedrock inference-profile pagination reached the configured page cap of '
                .self::MAX_INFERENCE_PROFILE_PAGES
                .' before all pages were retrieved; model list may be incomplete.'
            );
        }

        return $models;
    }

    /**
     * Extract normalised token usage from a Bedrock Converse response.
     *
     * Bedrock's usage fields mirror Anthropic's semantics: inputTokens is the
     * non-cached prompt count and cacheReadInputTokens / cacheWriteInputTokens
     * are separate buckets (present only on cache-supporting models).
     *
     * @param  array<string, mixed>  $response
     */
    public function extractUsage(array $response): Usage
    {
        $u = $response['usage'] ?? null;
        if (! is_array($u)) {
            return Usage::empty();
        }

        $input = (int) ($u['inputTokens'] ?? 0);
        $output = (int) ($u['outputTokens'] ?? 0);
        $cacheRead = (int) ($u['cacheReadInputTokens'] ?? 0);
        $cacheCreate = (int) ($u['cacheWriteInputTokens'] ?? 0);
        $total = isset($u['totalTokens'])
            ? (int) $u['totalTokens']
            : $input + $cacheRead + $cacheCreate + $output;

        return new Usage(
            inputTokens: $input,
            outputTokens: $output,
            totalTokens: $total,
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
                    fn (ContentBlock $b) => $this->contentBlockToBedrock($b),
                    $msg['content'],
                ),
            ];
        }, $messages);
    }

    private function contentBlockToBedrock(ContentBlock $block): array
    {
        if ($block->type === 'document') {
            $mime = (string) ($block->mimeType ?? '');

            if (isset(self::MIME_TO_IMAGE_FORMAT[$mime])) {
                return [
                    'image' => [
                        'format' => self::MIME_TO_IMAGE_FORMAT[$mime],
                        'source' => ['bytes' => $block->base64],
                    ],
                ];
            }

            return [
                'document' => [
                    'format' => $this->mimeToFormat($mime),
                    'name' => 'document',
                    'source' => ['bytes' => $block->base64],
                ],
            ];
        }

        return ['text' => $block->text ?? ''];
    }

    private function toolConfigToBedrock(ToolConfig $config): array
    {
        $tools = array_map(fn (ToolDefinition $t) => [
            'toolSpec' => [
                'name' => $t->name,
                'description' => $t->description,
                'inputSchema' => ['json' => $t->inputSchema->toArray()],
            ],
        ], $config->tools);

        $toolChoice = match ($config->choice->type) {
            ToolChoice::ANY => ['any' => (object) []],
            ToolChoice::TOOL => ['tool' => ['name' => $config->choice->toolName]],
            ToolChoice::NONE => null,
            default => ['auto' => (object) []],
        };

        $result = ['tools' => $tools];
        if ($toolChoice !== null) {
            $result['toolChoice'] = $toolChoice;
        }

        return $result;
    }

    /**
     * MIME → Bedrock DocumentBlock.format mapping. Every entry here matches one of
     * the nine `format` values accepted by the Converse API (pdf, csv, doc, docx,
     * xls, xlsx, html, txt, md).
     */
    private const MIME_TO_FORMAT = [
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/html' => 'html',
        'text/plain' => 'txt',
        'text/markdown' => 'md',
    ];

    /**
     * MIME → Bedrock ImageBlock.format mapping. Images use a different block shape
     * than documents in Bedrock Converse, so the client routes them based on MIME.
     */
    private const MIME_TO_IMAGE_FORMAT = [
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * MIME types accepted natively by Bedrock Converse as a document block.
     *
     * @return list<string>
     */
    public static function supportedDocumentMimeTypes(): array
    {
        return array_keys(self::MIME_TO_FORMAT);
    }

    /**
     * Cheap upfront check so callers can reject files before building a request.
     */
    public static function isSupportedDocumentMimeType(string $mimeType): bool
    {
        return isset(self::MIME_TO_FORMAT[$mimeType]);
    }

    /** @return list<string> */
    public static function supportedImageMimeTypes(): array
    {
        return array_keys(self::MIME_TO_IMAGE_FORMAT);
    }

    public static function isSupportedImageMimeType(string $mimeType): bool
    {
        return isset(self::MIME_TO_IMAGE_FORMAT[$mimeType]);
    }

    private function mimeToFormat(string $mimeType): string
    {
        if (! isset(self::MIME_TO_FORMAT[$mimeType])) {
            throw new GenAiFatalException(sprintf(
                'Bedrock Converse does not accept %s as a document block. '
                .'Supported types: %s.',
                $mimeType === '' ? '(no MIME type)' : $mimeType,
                implode(', ', array_keys(self::MIME_TO_FORMAT)),
            ));
        }

        return self::MIME_TO_FORMAT[$mimeType];
    }
}
