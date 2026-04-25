<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Bherila\GenAiLaravel\FileConversion\WordDocumentToPdf;
use Bherila\GenAiLaravel\Http\RetryStrategy;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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

    /**
     * MIME types the Anthropic Messages API accepts as a `document` content block.
     *
     * Per https://platform.claude.com/docs/en/build-with-claude/files, everything
     * else (docx, csv, md, html, …) must be converted to plain text by the caller
     * and sent inline as a text block. Spreadsheets (xlsx/xls/ods/csv) are converted
     * automatically when phpoffice/phpspreadsheet is installed.
     */
    private const SUPPORTED_DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'text/plain',
    ];

    /**
     * MIME types accepted as an Anthropic `image` content block.
     *
     * See https://platform.claude.com/docs/en/build-with-claude/vision — these
     * are sent through a different wire shape than documents, so the client
     * routes them to the image block automatically.
     */
    private const SUPPORTED_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private string $model;

    private int $maxTokens;

    private PendingRequest $http;

    private RetryStrategy $retry;

    public function __construct(
        string $apiKey,
        string $model = 'claude-sonnet-4-6',
        int $maxTokens = 8192,
        int $timeout = 240,
        ?RetryStrategy $retry = null,
    ) {
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->http = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ])->timeout($timeout);
        $this->retry = $retry ?? RetryStrategy::fromConfig();
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
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
     * MIME types accepted by the Anthropic document block.
     *
     * @return list<string>
     */
    public static function supportedDocumentMimeTypes(): array
    {
        return self::SUPPORTED_DOCUMENT_MIME_TYPES;
    }

    /**
     * Cheap upfront check so callers can reject files before building a request.
     */
    public static function isSupportedDocumentMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_DOCUMENT_MIME_TYPES, true);
    }

    /**
     * MIME types accepted as an Anthropic image block (vision).
     *
     * @return list<string>
     */
    public static function supportedImageMimeTypes(): array
    {
        return self::SUPPORTED_IMAGE_MIME_TYPES;
    }

    public static function isSupportedImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_IMAGE_MIME_TYPES, true);
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

        $response = $this->retry->execute(
            fn () => $this->http->post(self::API_BASE.'/v1/messages', $payload),
            'Anthropic Messages',
        );

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

            $payload = $this->retry->execute(
                fn () => $this->http->get(self::API_BASE.'/v1/models', $query),
                'Anthropic list models',
            )->json() ?? [];
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
            $mime = (string) $block->mimeType;

            if (self::isSupportedDocumentMimeType($mime)) {
                return [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mime,
                        'data' => $block->base64,
                    ],
                ];
            }

            // Images go through the `image` block shape, not `document`.
            if (self::isSupportedImageMimeType($mime)) {
                return [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $mime,
                        'data' => $block->base64,
                    ],
                ];
            }

            // Word documents (doc / docx / odt / rtf): render to PDF so the model
            // gets full formatting via Anthropic's native PDF pipeline. Requires
            // phpoffice/phpword plus a PDF renderer (dompdf / mpdf / tcpdf).
            if (WordDocumentToPdf::supports($mime) && WordDocumentToPdf::isAvailable()) {
                $pdfB64 = WordDocumentToPdf::convert((string) $block->base64, $mime);

                return [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $pdfB64,
                    ],
                ];
            }

            // Spreadsheets (xlsx / xls / ods / csv): inline the extracted cell data
            // as text rather than failing — Anthropic only accepts pdf and text/plain
            // as document blocks, so this is the recommended fallback path.
            if (SpreadsheetToText::supports($mime) && SpreadsheetToText::isAvailable()) {
                $text = SpreadsheetToText::convert((string) $block->base64, $mime);

                return ['type' => 'text', 'text' => $text];
            }

            throw new GenAiFatalException(sprintf(
                'Anthropic Messages API does not accept %s. Documents: %s. Images: %s. '
                .'Install phpoffice/phpword + dompdf/dompdf for automatic doc/docx → PDF, '
                .'or phpoffice/phpspreadsheet for xlsx/xls/ods/csv → text conversion. '
                .'For other formats, extract the content yourself and send it as text. '
                .'See https://platform.claude.com/docs/en/build-with-claude/files',
                $mime === '' ? '(no MIME type)' : $mime,
                implode(', ', self::SUPPORTED_DOCUMENT_MIME_TYPES),
                implode(', ', self::SUPPORTED_IMAGE_MIME_TYPES),
            ));
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
