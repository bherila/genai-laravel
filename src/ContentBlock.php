<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic content block for message payloads.
 *
 * Use ContentBlock::text() for text, ContentBlock::document() for inline
 * base64-encoded files, and ContentBlock::fileReference() for a file already
 * uploaded through a provider's File API. Clients convert to their native wire
 * format:
 *
 *   Gemini text      → {text: "..."}
 *   Gemini document  → {inline_data: {mime_type, data}}
 *   Gemini file ref  → {file_data: {mime_type, file_uri}}
 *   Bedrock text     → {text: "..."}
 *   Bedrock document → {document: {format, name, source: {bytes}}}
 *   Bedrock file ref → unsupported (Bedrock has no File API)
 *   Anthropic text     → {type:"text", text:"..."}
 *   Anthropic document → {type:"document", source:{type:"base64"|"text", media_type, data}}
 *   Anthropic file ref → {type:"document"|"image", source:{type:"file", file_id}}
 */
final class ContentBlock
{
    public const TYPE_TEXT = 'text';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_FILE_REFERENCE = 'file_reference';

    private function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $base64 = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $fileRef = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(type: self::TYPE_TEXT, text: $text);
    }

    /**
     * @param  string  $base64  Base64-encoded file content.
     * @param  string  $mimeType  MIME type (e.g. "application/pdf").
     */
    public static function document(string $base64, string $mimeType): self
    {
        return new self(type: self::TYPE_DOCUMENT, base64: $base64, mimeType: $mimeType);
    }

    /**
     * Reference a file already uploaded through the provider's File API.
     *
     * This is the provider-neutral counterpart to uploadFile(): the same message
     * can be built once and sent to any client whose supportsFileApi() is true,
     * without the caller writing a Gemini `file_data` part or an Anthropic
     * `source: {type: "file"}` block by hand.
     *
     * @param  string  $fileRef  The value uploadFile() returned (Gemini file URI, Anthropic file_id).
     * @param  string  $mimeType  MIME type of the referenced file; providers use it to pick a block shape.
     */
    public static function fileReference(string $fileRef, string $mimeType): self
    {
        return new self(type: self::TYPE_FILE_REFERENCE, mimeType: $mimeType, fileRef: $fileRef);
    }
}
