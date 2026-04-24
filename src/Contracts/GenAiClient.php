<?php

namespace Bherila\GenAiLaravel\Contracts;

use Bherila\GenAiLaravel\ContentBlock;
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
     * Hard limit in bytes for a single file/document block this provider accepts.
     */
    public static function maxFileBytes(): int;

    /**
     * Send a conversation turn and return the raw provider response.
     *
     * @param  string  $system  System prompt text (empty string to omit).
     * @param  list<array{role: string, content: list<ContentBlock>}>  $messages
     * @param  ToolConfig|null  $toolConfig  Tool definitions and calling strategy.
     * @return array<string, mixed>  Raw provider response array.
     */
    public function converse(string $system, array $messages, ?ToolConfig $toolConfig = null): array;

    /**
     * Upload a file to the provider's File API and return a reference URI/ID.
     *
     * Returns null when the provider does not support a separate file upload step.
     *
     * @param  resource|string  $fileContent
     * @return string|null  Provider file URI/ID, or null when unsupported.
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string;

    /**
     * Delete a previously uploaded file. No-op when unsupported.
     */
    public function deleteFile(string $fileRef): void;

    /**
     * Send a request referencing an already-uploaded file.
     *
     * Throws LogicException for providers without a File API (Bedrock, Anthropic).
     *
     * @return array<string, mixed>
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
     * @param  array<string, mixed>  $response
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public function extractToolCalls(array $response): array;

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
