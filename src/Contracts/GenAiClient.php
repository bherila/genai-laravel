<?php

namespace Bherila\GenAiLaravel\Contracts;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Exceptions\GenAiFileTooLargeException;
use Bherila\GenAiLaravel\Exceptions\GenAiUnsupportedOperationException;
use Bherila\GenAiLaravel\Exceptions\GenAiUploadException;
use Bherila\GenAiLaravel\ModelInfo;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\Usage;

/**
 * Provider-agnostic contract for GenAI clients.
 *
 * Supported providers: Google Gemini, AWS Bedrock (Anthropic Claude), Anthropic direct API.
 *
 * Tool definitions are expressed via ToolConfig / ToolDefinition / Schema / ToolChoice —
 * provider-specific wire formats are handled internally by each client.
 *
 * Message content uses ContentBlock objects; clients convert to their native format.
 */
interface GenAiClient
{
    /**
     * The provider identifier (e.g. "gemini", "bedrock", "anthropic").
     */
    public function provider(): string;

    /**
     * The model identifier in use (e.g. "claude-sonnet-4-6", "gemini-3.6-flash").
     * Used for logging and audit records so callers do not need to read provider config.
     */
    public function model(): string;

    /**
     * Maximum decoded size, in bytes, of one file sent inline (base64) in a message.
     *
     * The limit is MIME-dependent: providers cap images far lower than documents,
     * and the ceiling for inline content is the *request* budget rather than any
     * per-file allowance, so base64 expansion is already accounted for. Clients
     * enforce this themselves before a request leaves the process — a file over
     * the limit raises GenAiFileTooLargeException rather than a provider 400.
     */
    public static function maxInlineFileBytes(string $mimeType): int;

    /**
     * Maximum decoded size, in bytes, of one file sent through the provider's
     * File API, or null when the provider has no File API.
     *
     * This is typically orders of magnitude larger than the inline limit, which is
     * the whole reason to upload rather than inline.
     */
    public static function maxUploadedFileBytes(): ?int;

    /**
     * Maximum number of document blocks accepted in a single message, or null when
     * the provider documents no such cap.
     */
    public static function maxFilesPerMessage(): ?int;

    /**
     * Send a conversation turn and return the raw provider response.
     *
     * @param  string  $system  System prompt text (empty string to omit).
     * @param  list<array{role: string, content: list<ContentBlock>}>  $messages
     * @param  ToolConfig|null  $toolConfig  Tool definitions and calling strategy.
     * @return array<string, mixed> Raw provider response array.
     */
    public function converse(string $system, array $messages, ?ToolConfig $toolConfig = null): array;

    /**
     * Whether this provider exposes a File API that uploadFile() can use.
     *
     * Callers that want to degrade gracefully should branch on this rather than
     * catching GenAiUnsupportedOperationException.
     */
    public static function supportsFileApi(): bool;

    /**
     * Upload a file to the provider's File API and return a reference URI/ID.
     *
     * @param  resource|string  $fileContent
     * @return string Provider file URI/ID.
     *
     * @throws GenAiUnsupportedOperationException When the provider has no File API.
     * @throws GenAiUploadException When the provider rejected or failed the upload.
     * @throws GenAiFileTooLargeException When the file exceeds maxUploadedFileBytes().
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): string;

    /**
     * Delete a previously uploaded file. No-op for providers without a File API.
     */
    public function deleteFile(string $fileRef): void;

    /**
     * Send a request referencing an already-uploaded file.
     *
     * @return array<string, mixed>
     *
     * @throws GenAiUnsupportedOperationException When the provider has no File API.
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?ToolConfig $toolConfig = null): array;

    /**
     * Send a request with a single file embedded as base64 inline (no prior upload).
     *
     * @param  string  $fileBytes  Base64-encoded file content.
     * @param  string  $system  System prompt text (empty string to omit).
     * @return array<string, mixed>
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, string $system = '', ?ToolConfig $toolConfig = null): array;

    /**
     * Extract the text content from a raw provider response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractText(array $response): string;

    /**
     * Extract tool/function call results from a raw provider response.
     *
     * `id` is the provider's call identifier, which Anthropic and Bedrock both
     * require back on the matching tool-result block. Gemini correlates results
     * by function name instead and usually sends no id, so it can be an empty
     * string — build results with ContentBlock::toolResultFor(), which carries
     * both and stays portable.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function extractToolCalls(array $response): array;

    /**
     * Verify that the configured credentials are accepted by the provider.
     *
     * Makes the cheapest available read-only API call (a model-list endpoint)
     * and returns true when the provider accepts the credentials, or false when
     * the provider returns 401/403. Any other error (network failure, 5xx, etc.)
     * is re-thrown so callers can distinguish "bad key" from "service down".
     */
    public function checkCredentials(): bool;

    /**
     * List models available to this provider's credentials.
     *
     * Results are normalised into ModelInfo — provider-specific fields remain
     * accessible via ModelInfo::$raw. None of the supported providers return
     * pricing in their catalog, so cost fields are populated only when the
     * client was configured with an out-of-band pricing table.
     *
     * @return list<ModelInfo>
     */
    public function listModels(): array;

    /**
     * Extract normalised token-usage data from a raw provider response.
     *
     * Returns Usage::empty() when the provider omits usage (e.g. streaming chunks,
     * error responses). Token counts are mapped into non-overlapping buckets so
     * inputTokens + cacheReadInputTokens + cacheCreationInputTokens reflects total
     * input work billed.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractUsage(array $response): Usage;
}
