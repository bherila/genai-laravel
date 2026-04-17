<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic content block for message payloads.
 *
 * Use ContentBlock::text() for text and ContentBlock::document() for inline
 * base64-encoded files. Clients convert to their native wire format:
 *
 *   Gemini text     → {text: "..."}
 *   Gemini document → {inline_data: {mime_type, data}}
 *   Bedrock text     → {text: "..."}
 *   Bedrock document → {document: {format, name, source: {bytes}}}
 *   Anthropic text     → {type:"text", text:"..."}
 *   Anthropic document → {type:"document", source:{type:"base64", media_type, data}}
 */
final class ContentBlock
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $base64 = null,
        public readonly ?string $mimeType = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(type: 'text', text: $text);
    }

    /**
     * @param  string  $base64  Base64-encoded file content.
     * @param  string  $mimeType  MIME type (e.g. "application/pdf").
     */
    public static function document(string $base64, string $mimeType): self
    {
        return new self(type: 'document', base64: $base64, mimeType: $mimeType);
    }
}
