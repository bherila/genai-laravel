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
 *
 * toolCall() and toolResult() close a tool loop without provider-specific code:
 *
 *   Gemini    → {functionCall: {...}} / {functionResponse: {name, response}}
 *   Bedrock   → {toolUse: {...}}      / {toolResult: {toolUseId, content, status}}
 *   Anthropic → {type:"tool_use"}     / {type:"tool_result", tool_use_id, content}
 */
final class ContentBlock
{
    public const TYPE_TEXT = 'text';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_FILE_REFERENCE = 'file_reference';

    public const TYPE_TOOL_CALL = 'tool_call';

    public const TYPE_TOOL_RESULT = 'tool_result';

    /**
     * A part this package does not model, replayed to the provider verbatim.
     *
     * Providers attach state to assistant parts that a portable abstraction has
     * no business interpreting but must not drop — Gemini's thought signatures,
     * Anthropic's thinking blocks. Projecting a response down to text + tool
     * calls and rebuilding it loses exactly this, and the loss only shows up as
     * a provider-side validation error on the *next* turn.
     */
    public const TYPE_PROVIDER_RAW = 'provider_raw';

    /**
     * @param  array<string, mixed>|null  $toolInput
     * @param  string|array<mixed>|null  $toolResult
     * @param  array<string, mixed>  $providerMetadata  Opaque provider-owned keys, merged
     *                                                  back into the emitted part unchanged.
     */
    private function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $base64 = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $fileRef = null,
        public readonly ?string $toolCallId = null,
        public readonly ?string $toolName = null,
        public readonly ?array $toolInput = null,
        public readonly string|array|null $toolResult = null,
        public readonly bool $isError = false,
        public readonly array $providerMetadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $providerMetadata  Provider keys to replay alongside the text.
     */
    public static function text(string $text, array $providerMetadata = []): self
    {
        return new self(type: self::TYPE_TEXT, text: $text, providerMetadata: $providerMetadata);
    }

    /**
     * A provider part replayed verbatim, for content this package does not model.
     *
     * @param  array<string, mixed>  $part  The part exactly as the provider sent it.
     */
    public static function providerRaw(array $part): self
    {
        return new self(type: self::TYPE_PROVIDER_RAW, providerMetadata: $part);
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

    /**
     * A tool call the model made, replayed back into the conversation.
     *
     * Anthropic and Bedrock both require the assistant's tool call to appear in
     * the history before the matching result, so a tool loop cannot be closed
     * without this block. GenAiResponse::assistantMessage() builds it for you
     * from a response.
     *
     * @param  string  $id  Provider call ID, from the `id` key of an extractToolCalls() entry.
     * @param  array<string, mixed>  $input  Arguments the model passed.
     * @param  array<string, mixed>  $providerMetadata  Opaque state that arrived on this part
     *                                                  (e.g. a Gemini thought signature) and must
     *                                                  be replayed on the same part.
     */
    public static function toolCall(string $id, string $name, array $input, array $providerMetadata = []): self
    {
        return new self(
            type: self::TYPE_TOOL_CALL,
            toolCallId: $id,
            toolName: $name,
            toolInput: $input,
            providerMetadata: $providerMetadata,
        );
    }

    /**
     * The result of executing a tool, sent back to the model.
     *
     * Correlation differs by provider: Anthropic and Bedrock match a result to
     * its call by ID, while Gemini matches by function name and additionally
     * expects the call's `id` echoed back when the model sent one. Supplying both
     * keeps one message portable across all three — which is the whole point of
     * building the loop out of ContentBlocks. Prefer toolResultFor(), which fills
     * both in from the call it is answering.
     *
     * @param  string|array<mixed>  $result  Text, or a structured payload to hand back.
     * @param  bool  $isError  Marks the tool as having failed, so the model can recover.
     */
    public static function toolResult(
        string $toolCallId,
        string|array $result,
        bool $isError = false,
        string $toolName = '',
    ): self {
        return new self(
            type: self::TYPE_TOOL_RESULT,
            toolCallId: $toolCallId,
            toolName: $toolName,
            toolResult: $result,
            isError: $isError,
        );
    }

    /**
     * Build a tool result for one entry of GenAiResponse::$toolCalls.
     *
     * @param  array{id?: string, name?: string, input?: array<string, mixed>}  $toolCall
     * @param  string|array<mixed>  $result
     */
    public static function toolResultFor(array $toolCall, string|array $result, bool $isError = false): self
    {
        return self::toolResult(
            toolCallId: (string) ($toolCall['id'] ?? ''),
            result: $result,
            isError: $isError,
            toolName: (string) ($toolCall['name'] ?? ''),
        );
    }

    /**
     * The tool result rendered as text, for providers whose result block is a string.
     */
    public function toolResultAsText(): string
    {
        if (is_string($this->toolResult)) {
            return $this->toolResult;
        }

        return json_encode($this->toolResult ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * The tool result rendered as a JSON object, for providers whose result block
     * must be structured (Gemini's functionResponse).
     *
     * @return array<string, mixed>
     */
    public function toolResultAsArray(): array
    {
        // An error has to surface as {"error": …} whatever shape the result took,
        // otherwise a failed tool is indistinguishable from a successful one.
        if ($this->isError) {
            return ['error' => $this->toolResult];
        }

        // A JSON *array* is not a valid functionResponse.response — only an
        // object is — so a list gets wrapped rather than passed through.
        if (is_array($this->toolResult) && ! array_is_list($this->toolResult)) {
            return $this->toolResult;
        }

        return ['result' => $this->toolResult];
    }
}
