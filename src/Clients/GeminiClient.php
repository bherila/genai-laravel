<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiException;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
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
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileRef]],
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
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $fileBytes]],
                ],
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

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function contentBlockToGeminiPart(ContentBlock $block): array
    {
        return match ($block->type) {
            'document' => ['inline_data' => ['mime_type' => $block->mimeType, 'data' => $block->base64]],
            default => ['text' => $block->text ?? ''],
        };
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
