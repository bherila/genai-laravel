<?php

namespace Bherila\GenAiLaravel;

use Bherila\GenAiLaravel\Contracts\GenAiClient;

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
 * Calling generate() on two different clients is also valid — pass any
 * GenAiClient implementation (Anthropic, Bedrock, Gemini) to ::with().
 */
final class GenAiRequest
{
    private string $system = '';

    private string $promptText = '';

    /** @var list<array{base64: string, mimeType: string}> */
    private array $files = [];

    private ?ToolConfig $toolConfig = null;

    /** @var list<array{role: string, content: list<ContentBlock>}>|null */
    private ?array $rawMessages = null;

    private function __construct(private readonly GenAiClient $client) {}

    /**
     * Create a new request bound to the given provider client.
     */
    public static function with(GenAiClient $client): static
    {
        return new static($client);
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
    public function withFile(string $base64, string $mimeType): static
    {
        $clone = clone $this;
        $clone->files[] = ['base64' => $base64, 'mimeType' => $mimeType];

        return $clone;
    }

    /**
     * Set the inline files for this request (replaces any previously added files).
     *
     * @param  list<array{base64: string, mimeType: string}>  $files
     */
    public function withFiles(array $files): static
    {
        $clone = clone $this;
        $clone->files = $files;

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
        $messages = $this->rawMessages ?? $this->buildMessages();
        $raw = $this->client->converse($this->system, $messages, $this->toolConfig);

        return new GenAiResponse(
            text: $this->client->extractText($raw),
            toolCalls: $this->client->extractToolCalls($raw),
            raw: $raw,
        );
    }

    /** @return list<array{role: string, content: list<ContentBlock>}> */
    private function buildMessages(): array
    {
        $content = [];

        foreach ($this->files as $file) {
            $content[] = ContentBlock::document($file['base64'], $file['mimeType']);
        }

        if ($this->promptText !== '') {
            $content[] = ContentBlock::text($this->promptText);
        }

        return $content !== [] ? [['role' => 'user', 'content' => $content]] : [];
    }
}
