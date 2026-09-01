<?php

namespace Bherila\GenAiLaravel\Clients;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiFatalException;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\FileConversion\SpreadsheetToText;
use Bherila\GenAiLaravel\FileConversion\WordDocumentToPdf;
use Bherila\GenAiLaravel\FileLimits;
use Bherila\GenAiLaravel\Http\RetryStrategy;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use Bherila\GenAiLaravel\Usage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API implementation of GenAiClient.
 *
 * Uses the direct Anthropic API (api.anthropic.com), not AWS Bedrock.
 * Files must be embedded as base64 inline content blocks — supportsFileApi() is false.
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
     * Beta flag required by the Files API and by `source: {type: "file"}` blocks.
     * https://platform.claude.com/docs/en/build-with-claude/files
     */
    private const FILES_API_BETA = 'files-api-2025-04-14';

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

    private string $apiKey;

    private string $model;

    private int $maxTokens;

    private int $timeout;

    private PendingRequest $http;

    private RetryStrategy $retry;

    public function __construct(
        string $apiKey,
        string $model = 'claude-sonnet-4-6',
        int $maxTokens = 8192,
        int $timeout = 240,
        ?RetryStrategy $retry = null,
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->timeout = $timeout;
        $this->http = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta' => self::FILES_API_BETA,
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
     * Vision caps a single image at 5 MB. Documents have no separate per-file
     * allowance — they are bounded by the 32 MB Messages API request limit, and
     * base64 inflates bytes by a third, so 24 MB decoded is the usable ceiling.
     * https://platform.claude.com/docs/en/build-with-claude/pdf-support
     */
    public static function maxInlineFileBytes(string $mimeType): int
    {
        return self::isSupportedImageMimeType($mimeType)
            ? 5 * 1024 * 1024
            : intdiv(32 * 1024 * 1024 * 3, 4);
    }

    /**
     * Files API limit per uploaded file.
     * https://platform.claude.com/docs/en/build-with-claude/files
     */
    public static function maxUploadedFileBytes(): ?int
    {
        return 500 * 1024 * 1024;
    }

    /** Anthropic documents no per-message file-count cap. */
    public static function maxFilesPerMessage(): ?int
    {
        return null;
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

    public static function supportsFileApi(): bool
    {
        return true;
    }

    /**
     * Upload a file to the Anthropic Files API and return its `file_id`.
     *
     * Security note worth reading before you use this for multi-tenant data:
     * uploaded files are scoped to the API **workspace**, not to a user or a
     * conversation. Any key in the same workspace can reference the returned
     * file_id. Where tenants must not see each other's documents, give each one
     * its own workspace (and therefore its own key) — or keep sending bytes
     * inline, which stores nothing.
     * https://platform.claude.com/docs/en/build-with-claude/files
     *
     * @param  resource|string  $fileContent
     *
     * @throws GenAiFileTooLargeException When the file exceeds the Files API limit.
     * @throws GenAiUploadException When Anthropic rejects or fails the upload.
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): string
    {
        $size = FileLimits::contentLength($fileContent);
        if ($size !== null) {
            FileLimits::assertWithin($size, (int) self::maxUploadedFileBytes(), 'Anthropic Files API', 'the uploaded file');
        }

        $filename = $displayName !== '' ? $displayName : 'genai-upload-'.time();

        // Built from scratch rather than from $this->http: that one pins
        // Content-Type: application/json, which would break the multipart body.
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta' => self::FILES_API_BETA,
        ])
            ->attach('file', $fileContent, $filename, ['Content-Type' => $mimeType])
            ->timeout($this->timeout)
            ->post(self::API_BASE.'/v1/files');

        if (! $response->successful()) {
            throw new GenAiUploadException(
                'Anthropic Files API upload failed with HTTP '.$response->status().': '.$response->body(),
                status: $response->status(),
                body: $response->body(),
            );
        }

        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw new GenAiUploadException(
                'Anthropic Files API upload succeeded but returned no file id.',
                status: $response->status(),
                body: $response->body(),
            );
        }

        return $id;
    }

    /**
     * Delete an uploaded file.
     *
     * Failures are logged rather than thrown: deletion is cleanup, usually run
     * from a `finally` block, and throwing there would mask the original error.
     */
    public function deleteFile(string $fileRef): void
    {
        try {
            $response = $this->http->delete(self::API_BASE.'/v1/files/'.rawurlencode($fileRef));
            if (! $response->successful()) {
                Log::warning('Anthropic: failed to delete file', [
                    'file_ref' => $fileRef,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Anthropic: failed to delete file', ['file_ref' => $fileRef, 'error' => $e->getMessage()]);
        }
    }

    /**
     * List the files visible to this workspace.
     *
     * Provider-specific (the shared contract has no listing method); entries are
     * returned as Anthropic sends them.
     *
     * @return list<array<string, mixed>>
     */
    public function listFiles(): array
    {
        $files = [];
        $afterId = null;

        do {
            $query = ['limit' => 1000];
            if ($afterId !== null) {
                $query['after_id'] = $afterId;
            }

            $payload = $this->retry->execute(
                fn () => $this->http->get(self::API_BASE.'/v1/files', $query),
                'Anthropic list files',
            )->json() ?? [];

            foreach ($payload['data'] ?? [] as $entry) {
                if (is_array($entry)) {
                    $files[] = $entry;
                }
            }

            $hasMore = (bool) ($payload['has_more'] ?? false);
            $afterId = $hasMore && is_string($payload['last_id'] ?? null) ? $payload['last_id'] : null;
        } while ($afterId !== null);

        return $files;
    }

    /**
     * Retrieve metadata for one uploaded file (filename, mime_type, size_bytes, …).
     *
     * @return array<string, mixed>
     */
    public function fileMetadata(string $fileRef): array
    {
        $response = $this->retry->execute(
            fn () => $this->http->get(self::API_BASE.'/v1/files/'.rawurlencode($fileRef)),
            'Anthropic file metadata',
        );

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Send a Messages API request referencing an already-uploaded file.
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null): array
    {
        $messages = [[
            'role' => 'user',
            'content' => [
                ContentBlock::fileReference($fileRef, $mimeType),
                ContentBlock::text($prompt),
            ],
        ]];

        return $this->converse('', $messages, $toolConfig);
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

    public function checkCredentials(): bool
    {
        $response = $this->http->get(self::API_BASE.'/v1/models', ['limit' => 1]);
        if ($response->successful()) {
            return true;
        }
        if (in_array($response->status(), [401, 403], true)) {
            return false;
        }
        throw new GenAiFatalException('checkCredentials error '.$response->status().': '.$response->body());
    }

    /**
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
        if ($block->type === ContentBlock::TYPE_FILE_REFERENCE) {
            $mime = (string) $block->mimeType;
            $source = ['type' => 'file', 'file_id' => (string) $block->fileRef];

            return self::isSupportedImageMimeType($mime)
                ? ['type' => 'image', 'source' => $source]
                : ['type' => 'document', 'source' => $source];
        }

        if ($block->type === 'document') {
            $mime = (string) $block->mimeType;

            // Plain text uses a `text` source carrying the decoded content —
            // only PDFs go through the base64 source. Sending base64 under a
            // `text/plain` media type is silently accepted-then-misread.
            // https://platform.claude.com/docs/en/build-with-claude/citations
            if ($mime === 'text/plain') {
                $decoded = base64_decode((string) $block->base64, true);
                if ($decoded === false) {
                    throw new GenAiFatalException(
                        'Anthropic text/plain document block: content is not valid base64. '
                        .'ContentBlock::document() expects base64-encoded bytes.'
                    );
                }

                $this->assertInlineSizeWithinLimit((string) $block->base64, $mime);

                return [
                    'type' => 'document',
                    'source' => [
                        'type' => 'text',
                        'media_type' => 'text/plain',
                        'data' => $decoded,
                    ],
                ];
            }

            if (self::isSupportedDocumentMimeType($mime)) {
                $this->assertInlineSizeWithinLimit((string) $block->base64, $mime);

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
                $this->assertInlineSizeWithinLimit((string) $block->base64, $mime);

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
                $this->assertInlineSizeWithinLimit($pdfB64, 'application/pdf');

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

    /**
     * @throws GenAiFileTooLargeException
     */
    private function assertInlineSizeWithinLimit(string $base64, string $mimeType): void
    {
        FileLimits::assertWithin(
            FileLimits::decodedLength($base64),
            self::maxInlineFileBytes($mimeType),
            'Anthropic Messages',
            sprintf('inline %s content', $mimeType === '' ? 'file' : $mimeType),
        );
    }

    private function toolConfigToAnthropic(ToolConfig $config): array
    {
        $tools = array_map(fn (ToolDefinition $t) => [
            'name' => $t->name,
            'description' => $t->description,
            'input_schema' => $t->inputSchema->jsonSerialize(),
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
