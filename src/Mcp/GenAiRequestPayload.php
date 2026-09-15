<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\ToolConfig;

final readonly class GenAiRequestPayload
{
    /** @param list<array{role: string, content: list<ContentBlock>}> $messages */
    public function __construct(
        public string $system,
        public array $messages,
        public ?ToolConfig $toolConfig,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'system' => $this->system,
            'messages' => array_map(static fn (array $message): array => [
                'role' => $message['role'],
                'content' => array_map(self::serializeBlock(...), $message['content']),
            ], $this->messages),
            'tools' => array_map(static fn ($tool): array => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->inputSchema->jsonSerialize(),
            ], $this->toolConfig === null ? [] : $this->toolConfig->tools),
            'tool_choice' => $this->toolConfig === null ? null : [
                'type' => $this->toolConfig->choice->type,
                'name' => $this->toolConfig->choice->toolName,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function serializeBlock(ContentBlock $block): array
    {
        return match ($block->type) {
            ContentBlock::TYPE_TEXT => ['type' => 'text', 'text' => $block->text],
            ContentBlock::TYPE_DOCUMENT => ['type' => 'inline_attachment', 'base64' => $block->base64, 'mime_type' => $block->mimeType, 'name' => $block->name],
            ContentBlock::TYPE_STORED_ATTACHMENT => ['type' => 'stored_attachment', 'attachment' => $block->storedAttachment],
            ContentBlock::TYPE_TOOL_CALL => [
                'type' => 'tool_call',
                'id' => $block->toolCallId,
                'name' => $block->toolName,
                'input' => $block->toolInput === [] ? new \stdClass : $block->toolInput,
            ],
            ContentBlock::TYPE_TOOL_RESULT => ['type' => 'tool_result', 'id' => $block->toolCallId, 'name' => $block->toolName, 'result' => $block->toolResult, 'is_error' => $block->isError],
            ContentBlock::TYPE_FILE_REFERENCE => throw new \InvalidArgumentException('Provider file references cannot be enqueued for a subscription client.'),
            ContentBlock::TYPE_PROVIDER_RAW => throw new \InvalidArgumentException('Provider-owned raw content cannot be enqueued.'),
            default => throw new \InvalidArgumentException("Unsupported content block [{$block->type}]."),
        };
    }
}
