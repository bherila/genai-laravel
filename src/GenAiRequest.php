<?php

namespace Bherila\GenAiLaravel;

use Bherila\GenAiLaravel\Contracts\GenAiClient;
use Bherila\GenAiLaravel\Contracts\QueuedGenAiClient;
use Bherila\GenAiLaravel\Exceptions\GenAiUnsupportedOperationException;
use Bherila\GenAiLaravel\Instrumentation\SentryGenAiTracer;
use Bherila\GenAiLaravel\Mcp\EnqueueOptions;
use Bherila\GenAiLaravel\Mcp\GenAiRequestPayload;
use Bherila\GenAiLaravel\Mcp\PendingGenAiRequest;
use Bherila\GenAiLaravel\Mcp\StoredAttachment;

/**
 * Fluent builder for provider-agnostic AI requests.
 *
 * The provider is passed once at construction; every other method sets
 * parameters and returns an immutable clone, so builders can be reused:
 *
 *   $base = GenAiRequest::with($client)->system('You are an expert.');
 *   $r1   = $base->prompt('Summarise this.')->withFile($pdf1, 'application/pdf')->generate();
 *   $r2   = $base->prompt('Classify this.')->withFile($pdf2, 'application/pdf')->generate();
 *
 * Pass a synchronous GenAiClient to generate(), or a QueuedGenAiClient to
 * enqueue(); mixing the two contracts fails immediately with an actionable
 * unsupported-operation exception.
 */
final class GenAiRequest
{
    private string $system = '';

    private string $promptText = '';

    /** @var list<ContentBlock> */
    private array $files = [];

    private ?ToolConfig $toolConfig = null;

    /** @var list<array{role: string, content: list<ContentBlock>}>|null */
    private ?array $rawMessages = null;

    private function __construct(private readonly GenAiClient|QueuedGenAiClient $client) {}

    /**
     * Create a new request bound to a synchronous or queued client.
     */
    public static function with(GenAiClient|QueuedGenAiClient $client): static
    {
        return new self($client);
    }

    /**
     * Set the system prompt.
     */
    public function system(string $system): static
    {
        $clone = clone $this;
        $clone->system = $system;

        return $clone;
    }

    /**
     * Set the user prompt text.
     * When combined with withFile(s), files are prepended before the text.
     */
    public function prompt(string $text): static
    {
        $clone = clone $this;
        $clone->promptText = $text;

        return $clone;
    }

    /**
     * Add a single inline file (base64-encoded) to the request.
     */
    public function withFile(string $base64, string $mimeType, ?string $name = null): static
    {
        $clone = clone $this;
        $clone->files[] = ContentBlock::document($base64, $mimeType, $name);

        return $clone;
    }

    /**
     * Reference a file already uploaded through the provider's File API.
     *
     * Pairs with GenAiClient::uploadFile(), and keeps the call site identical to
     * withFile() — only providers where supportsFileApi() is true accept it.
     *
     *   $ref = $client->uploadFile($stream, 'application/pdf', 'report.pdf');
     *   try {
     *       $response = GenAiRequest::with($client)
     *           ->withFileRef($ref, 'application/pdf')
     *           ->prompt('Summarise this report.')
     *           ->generate();
     *   } finally {
     *       $client->deleteFile($ref);
     *   }
     */
    public function withFileRef(string $fileRef, string $mimeType): static
    {
        $clone = clone $this;
        $clone->files[] = ContentBlock::fileReference($fileRef, $mimeType);

        return $clone;
    }

    /** Add a storage-backed attachment without base64 encoding it. */
    public function withStoredAttachment(StoredAttachment $attachment): static
    {
        $clone = clone $this;
        $clone->files[] = ContentBlock::storedAttachment($attachment);

        return $clone;
    }

    /**
     * Set the files for this request (replaces any previously added files).
     *
     * Accepts ContentBlock instances — so uploaded-file references and inline
     * bytes can be mixed — or the `['base64' => …, 'mimeType' => …]` shape.
     *
     * @param  list<ContentBlock|array{base64: string, mimeType: string, name?: string}>  $files
     */
    public function withFiles(array $files): static
    {
        $clone = clone $this;
        $clone->files = array_map(
            fn (ContentBlock|array $file) => $file instanceof ContentBlock
                ? $file
                : ContentBlock::document($file['base64'], $file['mimeType'], $file['name'] ?? null),
            $files,
        );

        return $clone;
    }

    /**
     * Attach a tool configuration (tools + calling strategy).
     */
    public function tools(ToolConfig $config): static
    {
        $clone = clone $this;
        $clone->toolConfig = $config;

        return $clone;
    }

    /**
     * Override the message list directly (for multi-turn conversations).
     * When set, prompt() and withFiles() are ignored.
     *
     * @param  list<array{role: string, content: list<ContentBlock>}>  $messages
     */
    public function messages(array $messages): static
    {
        $clone = clone $this;
        $clone->rawMessages = $messages;

        return $clone;
    }

    /**
     * Execute the request and return a provider-agnostic response.
     */
    public function generate(): GenAiResponse
    {
        if (! $this->client instanceof GenAiClient) {
            throw new GenAiUnsupportedOperationException('Queued GenAI clients are asynchronous; call enqueue() and poll the returned request.');
        }
        $messages = $this->rawMessages ?? $this->buildMessages();
        foreach ($messages as $message) {
            foreach ($message['content'] as $block) {
                if ($block->type === ContentBlock::TYPE_STORED_ATTACHMENT) {
                    throw new GenAiUnsupportedOperationException('Storage-backed attachments are available only to queued clients. Use withFile() or withFileRef() for a synchronous provider.');
                }
            }
        }
        $raw = SentryGenAiTracer::trace(
            client: $this->client,
            inputMessages: $messages,
            system: $this->system,
            toolConfig: $this->toolConfig,
            callback: fn () => $this->client->converse($this->system, $messages, $this->toolConfig),
        );

        return new GenAiResponse(
            text: $this->client->extractText($raw),
            toolCalls: $this->client->extractToolCalls($raw),
            usage: $this->client->extractUsage($raw),
            raw: $raw,
            assistantMessage: $this->client->extractAssistantMessage($raw),
        );
    }

    public function enqueue(?EnqueueOptions $options = null): PendingGenAiRequest
    {
        if (! $this->client instanceof QueuedGenAiClient) {
            throw new GenAiUnsupportedOperationException('Synchronous GenAI clients do not support enqueue().');
        }

        return $this->client->enqueue(new GenAiRequestPayload(
            system: $this->system,
            messages: $this->rawMessages ?? $this->buildMessages(),
            toolConfig: $this->toolConfig,
        ), $options);
    }

    /** @return list<array{role: string, content: list<ContentBlock>}> */
    private function buildMessages(): array
    {
        $content = $this->files;

        if ($this->promptText !== '') {
            $content[] = ContentBlock::text($this->promptText);
        }

        return $content !== [] ? [['role' => 'user', 'content' => $content]] : [];
    }
}
