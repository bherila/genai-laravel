<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\Exceptions\GenAiUploadException;
use Bherila\GenAiLaravel\FileConversion\ConversionLimits;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Bherila\GenAiLaravel\FileConversion\WordDocumentToPdf;
use Bherila\GenAiLaravel\FileLimits;
use Bherila\GenAiLaravel\Http\RetryStrategy;
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
 *   model    — e.g. "gemini-3.6-flash" (default: "gemini-3.6-flash")
 *   timeout  — HTTP timeout in seconds (default: 240)
 *   response_mime_type — optional generation response MIME type; null disables MIME forcing
 */
class GeminiClient implements GenAiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com';

    private const FILE_API_URL = self::BASE_URL.'/upload/v1beta/files';

    private string $apiKey;

    private string $model;

    private int $timeout;

    private ?string $responseMimeType;

    private RetryStrategy $retry;

    /**
     * Ceilings for the Office-document conversions this client runs on the
     * caller's behalf. Best-effort resource guards, not a sandbox — see
     * ConversionLimits before feeding it documents from untrusted users.
     */
    private ConversionLimits $conversionLimits;

    public function __construct(
        string $apiKey,
        string $model = 'gemini-3.6-flash',
        int $timeout = 240,
        ?RetryStrategy $retry = null,
        ?string $responseMimeType = 'application/json',
        ?ConversionLimits $conversionLimits = null,
    ) {
        $this->apiKey = $apiKey;
        $this->model = self::normaliseModelId($model);
        $this->timeout = $timeout;
        $this->responseMimeType = $responseMimeType !== '' ? $responseMimeType : null;
        $this->retry = $retry ?? RetryStrategy::fromConfig();
        $this->conversionLimits = $conversionLimits ?? ConversionLimits::fromConfig();
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
     * Strip the `models/` resource-name prefix so an ID taken straight out of
     * listModels() (or Google's docs, which quote both forms) is call-ready.
     *
     * Tuned models keep their own `tunedModels/` prefix — it is part of the path
     * the API expects — so only the `models/` collection prefix is removed.
     */
    private static function normaliseModelId(string $model): string
    {
        return str_starts_with($model, 'models/') ? substr($model, strlen('models/')) : $model;
    }

    /**
     * Inline content shares the request budget with everything else in the turn,
     * and base64 inflates bytes by a third, so the decoded ceiling is 15 MB
     * regardless of MIME type. This is this package's conservative policy, not a
     * quoted Google ceiling — theirs has moved, and anything approaching it
     * belongs in the File API anyway.
     * https://ai.google.dev/gemini-api/docs/document-processing
     */
    public static function maxInlineFileBytes(string $mimeType): int
    {
        return intdiv(20 * 1024 * 1024 * 3, 4); // 15 MB decoded
    }

    /** Gemini File API limit: 2 GB per file. */
    public static function maxUploadedFileBytes(): ?int
    {
        return 2 * 1024 * 1024 * 1024;
    }

    /** Gemini documents no per-message block-count cap. */
    public static function maxInlineBlocksPerMessage(string $mimeType): ?int
    {
        return null;
    }

    /**
     * A conservative package policy rather than a quoted provider ceiling:
     * Google's documented inline limit has moved more than once, so this caps
     * what we will send instead of asserting what they will accept. Anything
     * near it belongs in the File API regardless.
     */
    public static function maxRequestBytes(): ?int
    {
        return 20 * 1024 * 1024;
    }

    public static function supportsFileApi(): bool
    {
        return true;
    }

    /**
     * Upload a file to the Gemini File API.
     *
     * @param  resource|string  $fileContent
     *
     * @throws GenAiFileTooLargeException When the file exceeds the 2 GB File API limit.
     * @throws GenAiUploadException When Gemini rejects or fails the upload.
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): string
    {
        $size = FileLimits::contentLength($fileContent);
        if ($size !== null) {
            FileLimits::assertWithin($size, (int) self::maxUploadedFileBytes(), 'Gemini File API', 'the uploaded file');
        }

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

            throw new GenAiUploadException(
                'Gemini File API upload failed with HTTP '.$response->status().': '.$response->body(),
                status: $response->status(),
                body: $response->body(),
            );
        }

        $ref = $response->json('file.uri') ?? $response->json('file.name');
        if (! is_string($ref) || $ref === '') {
            throw new GenAiUploadException(
                'Gemini File API upload succeeded but returned no file URI or name.',
                status: $response->status(),
                body: $response->body(),
            );
        }

        return $ref;
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
        if (! self::isSupportedDocumentMimeType($mimeType)
            && WordDocumentToPdf::supports($mimeType)
            && WordDocumentToPdf::isAvailable()
        ) {
            // Word doc → PDF: preserves formatting for Gemini's vision pipeline.
            $pdfB64 = WordDocumentToPdf::convert($fileBytes, $mimeType, $this->conversionLimits);
            $this->assertInlineSizeWithinLimit($pdfB64, 'application/pdf');
            $parts = [
                ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdfB64]],
                ['text' => $prompt],
            ];
        } elseif (! self::isSupportedDocumentMimeType($mimeType)
            && SpreadsheetToText::supports($mimeType)
            && SpreadsheetToText::isAvailable()
        ) {
            // Spreadsheet fallback: extract cell data to text rather than fail.
            $extracted = SpreadsheetToText::convert($fileBytes, $mimeType, $this->conversionLimits);
            $parts = [['text' => $extracted], ['text' => $prompt]];
        } else {
            $this->assertSupportedDocumentMimeType($mimeType);
            $this->assertInlineSizeWithinLimit($fileBytes, $mimeType);
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
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
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
                // Gemini correlates a functionResponse by name, not by id — it
                // only sends an id for parallel calls, so this is often ''.
                'id' => is_string($fn['id'] ?? null) ? $fn['id'] : '',
                'name' => (string) $fn['name'],
                'input' => is_array($fn['args'] ?? null) ? $fn['args'] : [],
            ];
        }

        return $calls;
    }

    /**
     * Gemini 3 attaches a `thoughtSignature` to function-call parts and rejects a
     * later turn whose history dropped it, so parts are replayed in order with
     * every unmodelled key intact rather than rebuilt from text + tool calls.
     *
     * @param  array<string, mixed>  $response
     * @return array{role: string, content: list<ContentBlock>}
     */
    public function extractAssistantMessage(array $response): array
    {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $content = [];

        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (isset($part['text']) && is_string($part['text'])) {
                    $content[] = ContentBlock::text($part['text'], self::partMetadata($part, ['text']));

                    continue;
                }

                $fn = $part['functionCall'] ?? null;
                if (is_array($fn) && isset($fn['name'])) {
                    $content[] = ContentBlock::toolCall(
                        id: is_string($fn['id'] ?? null) ? $fn['id'] : '',
                        name: (string) $fn['name'],
                        input: is_array($fn['args'] ?? null) ? $fn['args'] : [],
                        providerMetadata: self::partMetadata($part, ['functionCall']),
                    );

                    continue;
                }

                // Anything else (thought parts, future part kinds) is replayed as-is.
                $content[] = ContentBlock::providerRaw($part);
            }
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * The keys of a part this package does not model, kept for replay.
     *
     * @param  array<string, mixed>  $part
     * @param  list<string>  $modelled
     * @return array<string, mixed>
     */
    private static function partMetadata(array $part, array $modelled): array
    {
        foreach ($modelled as $key) {
            unset($part[$key]);
        }

        return $part;
    }

    public function checkCredentials(): bool
    {
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->withOptions(['timeout' => $this->timeout])
            ->get(self::BASE_URL.'/v1beta/models', ['pageSize' => 1]);
        if ($response->successful()) {
            return true;
        }
        if (in_array($response->status(), [401, 403], true)) {
            return false;
        }
        throw new GenAiFatalException('checkCredentials error '.$response->status().': '.$response->body());
    }

    /**
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

            $payload = $this->retry->execute(
                fn () => Http::withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->withOptions(['timeout' => $this->timeout])
                    ->get(self::BASE_URL.'/v1beta/models', $query),
                'Gemini list models',
            )->json() ?? [];
            foreach ($payload['models'] ?? [] as $entry) {
                // `name` is the resource name ("models/gemini-3.6-flash"); the value
                // generateContent expects is `baseModelId`. Returning the resource
                // name would build ".../models/models/gemini-3.6-flash:generateContent".
                // https://ai.google.dev/api/models
                $id = isset($entry['baseModelId']) && is_string($entry['baseModelId'])
                    ? $entry['baseModelId']
                    : self::normaliseModelId((string) ($entry['name'] ?? ''));
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

    /**
     * @throws GenAiFileTooLargeException
     */
    private function assertInlineSizeWithinLimit(string $base64, string $mimeType): void
    {
        FileLimits::assertWithin(
            FileLimits::decodedLength($base64),
            self::maxInlineFileBytes($mimeType),
            'Gemini generateContent',
            sprintf('inline %s content', $mimeType === '' ? 'file' : $mimeType),
        );
    }

    private function assertSupportedDocumentMimeType(string $mimeType): void
    {
        if (self::isSupportedDocumentMimeType($mimeType)) {
            return;
        }

        throw new GenAiFatalException(sprintf(
            'Gemini does not accept %s as a document. '
            .'Supported types: %s. Only PDF gets native vision understanding; '
            .'text/* types are extracted as plain text. Install phpoffice/phpword + '
            .'dompdf/dompdf for automatic doc/docx → PDF, or phpoffice/phpspreadsheet '
            .'for xlsx/xls/ods/csv → text conversion. '
            .'See https://ai.google.dev/gemini-api/docs/document-processing',
            $mimeType === '' ? '(no MIME type)' : $mimeType,
            implode(', ', self::SUPPORTED_DOCUMENT_MIME_TYPES),
        ));
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    private function contentBlockToGeminiPart(ContentBlock $block): array
    {
        if ($block->type === ContentBlock::TYPE_PROVIDER_RAW) {
            return $block->providerMetadata;
        }

        if ($block->type === ContentBlock::TYPE_TOOL_CALL) {
            $call = [
                'name' => (string) $block->toolName,
                'args' => ($block->toolInput ?? []) === [] ? (object) [] : $block->toolInput,
            ];
            if ((string) $block->toolCallId !== '') {
                $call['id'] = (string) $block->toolCallId;
            }

            // thoughtSignature and friends ride back on the part they arrived on.
            return $block->providerMetadata + ['functionCall' => $call];
        }

        if ($block->type === ContentBlock::TYPE_TOOL_RESULT) {
            if ((string) $block->toolName === '') {
                throw new GenAiFatalException(
                    'Gemini matches a tool result to its call by function name, not by id, so a '
                    .'tool_result block needs one. Build it with ContentBlock::toolResultFor($call, …) '
                    .'or pass toolName: to ContentBlock::toolResult().'
                );
            }

            $response = [
                'name' => (string) $block->toolName,
                // functionResponse.response must be a JSON object, never a scalar.
                'response' => $block->toolResultAsArray() === [] ? (object) [] : $block->toolResultAsArray(),
            ];
            if ((string) $block->toolCallId !== '') {
                $response['id'] = (string) $block->toolCallId;
            }

            return ['functionResponse' => $response];
        }

        if ($block->type === ContentBlock::TYPE_FILE_REFERENCE) {
            $mime = (string) $block->mimeType;
            $this->assertSupportedDocumentMimeType($mime);

            return ['file_data' => ['mime_type' => $mime, 'file_uri' => (string) $block->fileRef]];
        }

        if ($block->type === 'document') {
            $mime = (string) $block->mimeType;

            if (! self::isSupportedDocumentMimeType($mime)
                && WordDocumentToPdf::supports($mime)
                && WordDocumentToPdf::isAvailable()
            ) {
                $pdfB64 = WordDocumentToPdf::convert((string) $block->base64, $mime, $this->conversionLimits);
                $this->assertInlineSizeWithinLimit($pdfB64, 'application/pdf');

                return ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $pdfB64]];
            }

            if (! self::isSupportedDocumentMimeType($mime)
                && SpreadsheetToText::supports($mime)
                && SpreadsheetToText::isAvailable()
            ) {
                return ['text' => SpreadsheetToText::convert((string) $block->base64, $mime, $this->conversionLimits)];
            }

            $this->assertSupportedDocumentMimeType($mime);
            $this->assertInlineSizeWithinLimit((string) $block->base64, $mime);

            return ['inline_data' => ['mime_type' => $mime, 'data' => $block->base64]];
        }

        return $block->providerMetadata + ['text' => $block->text ?? ''];
    }

    /**
     * Merge toolConfig into the payload, or apply the configured response MIME type.
     */
    private function applyToolConfig(array $payload, ?ToolConfig $toolConfig): array
    {
        if ($toolConfig !== null) {
            return array_merge($payload, $this->toolConfigToGemini($toolConfig));
        }

        if ($this->responseMimeType !== null) {
            $payload['generationConfig'] = ['response_mime_type' => $this->responseMimeType];
        }

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
            $properties = array_map(
                fn ($prop) => $this->schemaToGemini($prop),
                $jsonSchema['properties'],
            );
            // Encode an empty property map as a JSON object, not `[]`.
            $result['properties'] = $properties === [] ? (object) [] : $properties;
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
        FileLimits::assertRequestWithin($payload, self::maxRequestBytes(), 'Gemini generateContent');

        $url = self::BASE_URL."/v1beta/models/{$this->model}:generateContent";

        $response = $this->retry->execute(
            fn () => Http::withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->withOptions(['timeout' => $this->timeout])->post($url, $payload),
            'Gemini generateContent',
        );

        return $response->json() ?? [];
    }
}
