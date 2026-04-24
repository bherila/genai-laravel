<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini implementation of GenAiClient.
 *
 * Uses the Gemini File API for file uploads (avoids embedding large base64 blobs
 * in every request) and the generateContent API for inference.
 *
 * ToolConfig is translated to Gemini function_declarations + functionCallingConfig.
 * Schema types are converted from JSON Schema (lowercase) to Gemini (UPPERCASE).
 * ContentBlock objects are converted to Gemini parts format.
 *
 * Config keys (all under genai.providers.gemini):
 *   api_key  — required; may be per-user or site-wide
 *   model    — e.g. "gemini-2.0-flash" (default: "gemini-2.0-flash")
 *   timeout  — HTTP timeout in seconds (default: 240)
 */
class GeminiClient implements GenAiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';

    private const FILE_API_URL = self::BASE_URL.'/upload/v1beta/files';

    private string $apiKey;

    private string $model;

    private int $timeout;

    public function __construct(string $apiKey, string $model = 'gemini-2.0-flash', int $timeout = 240)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    public function provider(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Gemini File API limit: 2 GB per file.
     * In practice, keep documents under 20 MB for reliable extraction.
     */
    public static function maxFileBytes(): int
    {
        return 20 * 1024 * 1024; // 20 MB practical limit
    }

    /**
     * Upload a file to the Gemini File API.
     *
     * @param  resource|string  $fileContent
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
    {
        $name = $displayName !== '' ? $displayName : 'genai-upload-'.time();

        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->attach('file', $fileContent, 'upload', ['Content-Type' => $mimeType])
            ->withOptions(['timeout' => $this->timeout])
            ->post(self::FILE_API_URL, [
                'file' => ['display_name' => $name],
            ]);

        if (! $response->successful()) {
            Log::error('Gemini File API upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 400) {
                throw new GenAiFatalException('File rejected by Gemini: '.$response->body());
            }

            return null;
        }

        return $response->json('file.uri') ?? $response->json('file.name');
    }

    /**
     * Delete a file from the Gemini File API to free quota.
     */
    public function deleteFile(string $fileRef): void
    {
        try {
            $fileName = $fileRef;
            if (! str_starts_with($fileName, 'files/')) {
                if (preg_match('/files\/[a-zA-Z0-9_-]+/', $fileRef, $matches)) {
                    $fileName = $matches[0];
                }
            }

            Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->delete(self::BASE_URL."/v1beta/{$fileName}");
        } catch (\Throwable $e) {
            Log::warning('Gemini: failed to delete file', ['file_ref' => $fileRef, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Send a generateContent request referencing an already-uploaded file.
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null): array
    {
        $this->assertSupportedDocumentMimeType($mimeType);

        $payload = [
            'contents' => [[
                'parts' => [
                    ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileRef]],
                    ['text' => $prompt],
                ],
            ]],
        ];

        $payload = $this->applyToolConfig($payload, $toolConfig);

        return $this->doGenerateContent($payload);
    }

    /**
     * Send a generateContent request with base64-encoded file bytes embedded inline.
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, string $system = '', ?ToolConfig $toolConfig = null): array
    {
        // Spreadsheet fallback: extract cell data to text rather than fail.
        if (! self::isSupportedDocumentMimeType($mimeType)
            && SpreadsheetToText::supports($mimeType)
            && SpreadsheetToText::isAvailable()
        ) {
            $extracted = SpreadsheetToText::convert($fileBytes, $mimeType);
            $parts = [['text' => $extracted], ['text' => $prompt]];
        } else {
            $this->assertSupportedDocumentMimeType($mimeType);
            $parts = [
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $fileBytes]],
                ['text' => $prompt],
            ];
        }

        $payload = [
            'contents' => [[
                'parts' => $parts,
            ]],
        ];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $payload = $this->applyToolConfig($payload, $toolConfig);

        return $this->doGenerateContent($payload);
    }

    /**
     * Text-only (or multi-modal via ContentBlock) conversation turn.
     *
     * @param  list<array{role: string, content: list<ContentBlock>}>  $messages
     */
    public function converse(string $system, array $messages, ?ToolConfig $toolConfig = null): array
    {
        $contents = [];
        foreach ($messages as $message) {
            $parts = [];
            foreach ($message['content'] as $block) {
                $parts[] = $this->contentBlockToGeminiPart($block);
            }
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        $payload = ['contents' => $contents];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $payload = $this->applyToolConfig($payload, $toolConfig);

        return $this->doGenerateContent($payload);
    }

    /**
     * Extract concatenated text from a Gemini response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractText(array $response): string
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts)) {
            return '';
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    /**
     * Extract function/tool calls from a Gemini response.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public function extractToolCalls(array $response): array
    {
        $calls = [];
        $parts = $response['candidates'][0]['content']['parts'] ?? [];

        if (! is_array($parts)) {
            return $calls;
        }

        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            $fn = $part['functionCall'] ?? null;
            if (! is_array($fn) || ! isset($fn['name'])) {
                continue;
            }
            $calls[] = [
                'name' => (string) $fn['name'],
                'input' => is_array($fn['args'] ?? null) ? $fn['args'] : [],
            ];
        }

        return $calls;
    }

    /**
     * List models available to this Gemini API key.
     *
     * Paginates via `pageToken` until the API stops returning one. The response
     * filters out models that don't support `generateContent` — those can't be
     * called through this package so including them would be misleading.
     *
     * @return list<ModelInfo>
     */
    public function listModels(): array
    {
        $models = [];
        $pageToken = null;

        do {
            $query = ['pageSize' => 1000];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                ->withOptions(['timeout' => $this->timeout])
                ->get(self::BASE_URL.'/v1beta/models', $query);

            if (! $response->successful()) {
                Log::error('Gemini list models failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                if ($response->status() === 429) {
                    throw new GenAiRateLimitException('Gemini rate limit exceeded.');
                }
                if (in_array($response->status(), [400, 401, 403, 404], true)) {
                    throw new GenAiFatalException('Gemini list models error: '.$response->body());
                }
                throw new GenAiException('Gemini API error '.$response->status().': '.$response->body());
            }

            $payload = $response->json() ?? [];
            foreach ($payload['models'] ?? [] as $entry) {
                $id = (string) ($entry['name'] ?? '');
                if ($id === '') {
                    continue;
                }
                $methods = $entry['supportedGenerationMethods'] ?? [];
                if (is_array($methods) && ! in_array('generateContent', $methods, true)) {
                    continue;
                }

                $models[] = new ModelInfo(
                    id: $id,
                    name: (string) ($entry['displayName'] ?? $id),
                    provider: 'gemini',
                    description: isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : null,
                    inputTokenLimit: isset($entry['inputTokenLimit']) ? (int) $entry['inputTokenLimit'] : null,
                    outputTokenLimit: isset($entry['outputTokenLimit']) ? (int) $entry['outputTokenLimit'] : null,
                    raw: is_array($entry) ? $entry : [],
                );
            }

            $pageToken = is_string($payload['nextPageToken'] ?? null) && $payload['nextPageToken'] !== ''
                ? $payload['nextPageToken']
                : null;
        } while ($pageToken !== null);

        return $models;
    }

    /**
     * Extract normalised token usage from a Gemini generateContent response.
     *
     * Gemini's promptTokenCount is inclusive of cached tokens — we subtract
     * cachedContentTokenCount so inputTokens represents only the non-cached
     * prompt portion, matching the non-overlapping bucket contract used by
     * the Anthropic and Bedrock mappers.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractUsage(array $response): Usage
    {
        $u = $response['usageMetadata'] ?? null;
        if (! is_array($u)) {
            return Usage::empty();
        }

        $prompt = (int) ($u['promptTokenCount'] ?? 0);
        $output = (int) ($u['candidatesTokenCount'] ?? 0);
        $cached = (int) ($u['cachedContentTokenCount'] ?? 0);
        $total = isset($u['totalTokenCount']) ? (int) $u['totalTokenCount'] : $prompt + $output;

        $nonCachedInput = $prompt - $cached;
        if ($nonCachedInput < 0) {
            $nonCachedInput = 0;
        }

        return new Usage(
            inputTokens: $nonCachedInput,
            outputTokens: $output,
            totalTokens: $total,
            cacheReadInputTokens: $cached,
            cacheCreationInputTokens: 0,
            raw: $u,
        );
    }

    /**
     * MIME types that pass Gemini's document-understanding pipeline.
     *
     * PDF is the only format with real vision understanding (charts, layout,
     * formatting). The text/* and application/xml entries are accepted by the
     * API but the model sees them as extracted plain text — per Google's docs,
     * "document vision only meaningfully understands PDFs". DOCX / XLSX / other
     * Office formats are not accepted: convert them to PDF (for layout) or plain
     * text (for content-only) before sending.
     *
     * See https://ai.google.dev/gemini-api/docs/document-processing
     */
    private const SUPPORTED_DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'text/plain',
        'text/markdown',
        'text/html',
        'application/xml',
        // Images — Gemini handles all of these via the same inline_data shape
        // as documents, so no separate block type is needed.
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /** @return list<string> */
    public static function supportedDocumentMimeTypes(): array
    {
        return self::SUPPORTED_DOCUMENT_MIME_TYPES;
    }

    public static function isSupportedDocumentMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_DOCUMENT_MIME_TYPES, true);
    }

    private function assertSupportedDocumentMimeType(string $mimeType): void
    {
        if (self::isSupportedDocumentMimeType($mimeType)) {
            return;
        }

        throw new GenAiFatalException(sprintf(
            'Gemini does not accept %s as a document. '
            .'Supported types: %s. Only PDF gets native vision understanding; '
            .'text/* types are extracted as plain text. Convert docx / xlsx / other '
            .'Office formats to PDF or plain text first. '
            .'See https://ai.google.dev/gemini-api/docs/document-processing',
            $mimeType === '' ? '(no MIME type)' : $mimeType,
            implode(', ', self::SUPPORTED_DOCUMENT_MIME_TYPES),
        ));
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function contentBlockToGeminiPart(ContentBlock $block): array
    {
        if ($block->type === 'document') {
            $mime = (string) $block->mimeType;

            if (! self::isSupportedDocumentMimeType($mime)
                && SpreadsheetToText::supports($mime)
                && SpreadsheetToText::isAvailable()
            ) {
                return ['text' => SpreadsheetToText::convert((string) $block->base64, $mime)];
            }

            $this->assertSupportedDocumentMimeType($mime);

            return ['inline_data' => ['mime_type' => $mime, 'data' => $block->base64]];
        }

        return ['text' => $block->text ?? ''];
    }

    /**
     * Merge toolConfig into the payload, or fall back to JSON-mode generation.
     */
    private function applyToolConfig(array $payload, ?ToolConfig $toolConfig): array
    {
        if ($toolConfig !== null) {
            return array_merge($payload, $this->toolConfigToGemini($toolConfig));
        }

        $payload['generationConfig'] = ['response_mime_type' => 'application/json'];

        return $payload;
    }

    private function toolConfigToGemini(ToolConfig $config): array
    {
        $functionDeclarations = array_map(fn (ToolDefinition $t) => [
            'name' => $t->name,
            'description' => $t->description,
            'parameters' => $this->schemaToGemini($t->inputSchema->toArray()),
        ], $config->tools);

        $mode = match ($config->choice->type) {
            ToolChoice::AUTO => 'AUTO',
            ToolChoice::ANY => 'ANY',
            ToolChoice::NONE => 'NONE',
            ToolChoice::TOOL => 'ANY',
        };

        $functionCallingConfig = ['mode' => $mode];
        if ($config->choice->type === ToolChoice::TOOL && $config->choice->toolName !== null) {
            $functionCallingConfig['allowedFunctionNames'] = [$config->choice->toolName];
        }

        return [
            'tools' => [['function_declarations' => $functionDeclarations]],
            'toolConfig' => ['functionCallingConfig' => $functionCallingConfig],
        ];
    }

    /** Recursively convert JSON Schema (lowercase) to Gemini schema (UPPERCASE). */
    private function schemaToGemini(array $jsonSchema): array
    {
        $typeMap = [
            'string' => 'STRING',
            'number' => 'NUMBER',
            'integer' => 'INTEGER',
            'boolean' => 'BOOLEAN',
            'object' => 'OBJECT',
            'array' => 'ARRAY',
        ];

        // Handle nullable union types like ['string', 'null']
        $rawType = $jsonSchema['type'] ?? 'string';
        if (is_array($rawType)) {
            $rawType = array_values(array_filter($rawType, fn ($t) => $t !== 'null'))[0] ?? 'string';
        }

        $result = ['type' => $typeMap[$rawType] ?? strtoupper($rawType)];

        if (isset($jsonSchema['description'])) {
            $result['description'] = $jsonSchema['description'];
        }
        if (isset($jsonSchema['enum'])) {
            $result['enum'] = $jsonSchema['enum'];
        }

        if ($rawType === 'object' && isset($jsonSchema['properties'])) {
            $result['properties'] = array_map(
                fn ($prop) => $this->schemaToGemini($prop),
                $jsonSchema['properties'],
            );
            if (! empty($jsonSchema['required'])) {
                $result['required'] = $jsonSchema['required'];
            }
        }

        if ($rawType === 'array' && isset($jsonSchema['items'])) {
            $result['items'] = $this->schemaToGemini($jsonSchema['items']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GenAiRateLimitException
     * @throws GenAiFatalException
     * @throws GenAiException
     */
    private function doGenerateContent(array $payload): array
    {
        $url = self::BASE_URL."/v1beta/models/{$this->model}:generateContent";

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->withOptions(['timeout' => $this->timeout])->post($url, $payload);

        if (! $response->successful()) {
            Log::error('Gemini generateContent failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->status() === 429) {
                throw new GenAiRateLimitException('Gemini rate limit exceeded.');
            }

            if ($response->status() === 400) {
                throw new GenAiFatalException('Gemini bad request: '.$response->body());
            }

            throw new GenAiException('Gemini API error '.$response->status().': '.$response->body());
        }

        return $response->json() ?? [];
    }
}
