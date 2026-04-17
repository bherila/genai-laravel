<?php

namespace Bherila\GenAiLaravel\Contracts;

/**
 * Provider-agnostic contract for GenAI clients.
 *
 * Each method maps to the provider's native API in the most direct way possible.
 * Implementations handle authentication, retries, and error normalization internally.
 *
 * Supported providers: Google Gemini, AWS Bedrock (Anthropic Claude).
 * Future: Anthropic direct API, OpenAI.
 */
interface GenAiClient
{
    /**
     * The provider identifier (e.g. "gemini", "bedrock").
     */
    public function provider(): string;

    /**
     * Hard limit in bytes for a single file/document block this provider accepts.
     * Callers must reject files larger than this before sending.
     */
    public static function maxFileBytes(): int;

    /**
     * Send a text-only conversation turn and return the raw provider response.
     *
     * @param  list<array{text: string}>  $system  System prompt blocks (provider-specific rendering).
     * @param  list<array{role: string, content: list<array<string, mixed>>}>  $messages  Conversation turns.
     * @param  array<string, mixed>|null  $toolConfig  Tool definitions and calling strategy.
     * @return array<string, mixed>  Raw provider response array.
     */
    public function converse(array $system, array $messages, ?array $toolConfig = null): array;

    /**
     * Upload a file to the provider's File API and return a reference URI/ID.
     *
     * Not all providers have a File API (Bedrock embeds bytes inline).
     * Returns null when the provider does not support a separate file upload step.
     *
     * @param  resource|string  $fileContent  Stream resource or raw binary string.
     * @param  string  $mimeType  File MIME type (e.g. "application/pdf").
     * @param  string  $displayName  Optional human-readable name for the uploaded file.
     * @return string|null  Provider file URI/ID, or null when unsupported.
     */
    public function uploadFile(mixed $fileContent, string $mimeType, string $displayName = ''): ?string;

    /**
     * Delete a previously uploaded file from the provider's File API.
     * No-op when the provider does not support a separate file upload step.
     *
     * @param  string  $fileRef  The URI/ID returned by uploadFile().
     */
    public function deleteFile(string $fileRef): void;

    /**
     * Send a generateContent / chat request that references an already-uploaded file.
     *
     * For providers without a File API (e.g. Bedrock), the file bytes must be embedded
     * inline instead. Use converse() with inline document blocks in that case.
     *
     * @param  string  $fileRef  The URI/ID returned by uploadFile().
     * @param  string  $mimeType  MIME type of the file.
     * @param  string  $prompt  User prompt text.
     * @param  array<string, mixed>|null  $toolConfig  Optional tool definitions.
     * @return array<string, mixed>  Raw provider response array.
     */
    public function converseWithFileRef(string $fileRef, string $mimeType, string $prompt, ?array $toolConfig = null): array;

    /**
     * Send a request with file bytes embedded inline (no prior upload required).
     *
     * For providers that have a File API (e.g. Gemini), use uploadFile() + converseWithFileRef()
     * instead to avoid re-uploading for multi-turn conversations.
     *
     * @param  string  $fileBytes  Base64-encoded file content.
     * @param  string  $mimeType  MIME type of the file.
     * @param  string  $prompt  User prompt text.
     * @param  list<array{text: string}>  $system  System prompt blocks.
     * @param  array<string, mixed>|null  $toolConfig  Optional tool definitions.
     * @return array<string, mixed>  Raw provider response array.
     */
    public function converseWithInlineFile(string $fileBytes, string $mimeType, string $prompt, array $system = [], ?array $toolConfig = null): array;

    /**
     * Extract the text content from a provider response.
     * Returns an empty string when the response contains no text parts.
     *
     * @param  array<string, mixed>  $response  Raw provider response from any converse* method.
     */
    public function extractText(array $response): string;

    /**
     * Extract tool/function call results from a provider response.
     *
     * Returns a list of ['name' => string, 'input' => array] pairs, one per tool call.
     * Returns an empty array when the response contains no tool calls.
     *
     * @param  array<string, mixed>  $response  Raw provider response from any converse* method.
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    public function extractToolCalls(array $response): array;
}
