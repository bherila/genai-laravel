<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiRateLimitException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini implementation of GenAiClient.
 *
 * Uses the Gemini File API for file uploads (avoids embedding large base64 blobs
 * in every request) and the generateContent API for inference.
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
     * Accepts a stream resource or a raw binary string.
     * Returns the file URI (e.g., "files/abc123xyz") for use in converseWithFileRef().
     * The file is automatically deleted after 48 hours by Google.
     *
     * @param  resource|string  $fileContent
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string
    {
        $name = $displayName !== '' ? $displayName : 'genai-upload-'.time();

        $request = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->attach('file', $fileContent, 'upload', ['Content-Type' => $mimeType])
            ->withOptions(['timeout' => $this->timeout]);

        $response = $request->post(self::FILE_API_URL, [
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
     *
     * @param  array<string, mixed>|null  $toolConfig  Gemini tool config shape:
     *   ['tools' => [['function_declarations' => [...]]], 'toolConfig' => ['functionCallingConfig' => [...]]]
     * @return array<string, mixed>
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?array $toolConfig = null): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['file_data' => ['mime_type' => $mimeType, 'file_uri' => $fileRef]],
                ],
            ]],
        ];

        if ($toolConfig !== null) {
            $payload = array_merge($payload, $toolConfig);
        } else {
            $payload['generationConfig'] = ['response_mime_type' => 'application/json'];
        }

        return $this->doGenerateContent($payload);
    }

    /**
     * Send a generateContent request with base64-encoded file bytes embedded inline.
     *
     * @param  list<array{text: string}>  $system
     * @param  array<string, mixed>|null  $toolConfig
     * @return array<string, mixed>
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, array $system = [], ?array $toolConfig = null): array
    {
        $parts = [
            ['text' => $prompt],
            ['inline_data' => ['mime_type' => $mimeType, 'data' => $fileBytes]],
        ];

        // Gemini doesn't have a dedicated system instruction in generateContent for all models,
        // but v1beta supports systemInstruction for Gemini 1.5+
        $payload = [
            'contents' => [['parts' => $parts]],
        ];

        if ($system !== []) {
            $payload['systemInstruction'] = [
                'parts' => array_map(fn ($s) => ['text' => $s['text']], $system),
            ];
        }

        if ($toolConfig !== null) {
            $payload = array_merge($payload, $toolConfig);
        } else {
            $payload['generationConfig'] = ['response_mime_type' => 'application/json'];
        }

        return $this->doGenerateContent($payload);
    }

    /**
     * Text-only conversation turn.
     * Gemini maps system prompts to systemInstruction; messages map to contents.
     *
     * @param  list<array{text: string}>  $system
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages
     * @param  array<string, mixed>|null  $toolConfig
     * @return array<string, mixed>
     */
    public function converse(array $system, array $messages, ?array $toolConfig = null): array
    {
        $contents = [];
        foreach ($messages as $message) {
            $parts = [];
            foreach ($message['content'] as $block) {
                if (isset($block['text'])) {
                    $parts[] = ['text' => $block['text']];
                }
            }
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => $parts,
            ];
        }

        $payload = ['contents' => $contents];

        if ($system !== []) {
            $payload['systemInstruction'] = [
                'parts' => array_map(fn ($s) => ['text' => $s['text']], $system),
            ];
        }

        if ($toolConfig !== null) {
            $payload = array_merge($payload, $toolConfig);
        } else {
            $payload['generationConfig'] = ['response_mime_type' => 'application/json'];
        }

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
                'input' => is_array($fn['args']) ? $fn['args'] : [],
            ];
        }

        return $calls;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GenAiRateLimitException
     * @throws GenAiFatalException
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
