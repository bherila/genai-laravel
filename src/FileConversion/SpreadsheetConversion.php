<?php

namespace Bherila\GenAiLaravel\FileConversion;

/**
 * A spreadsheet rendered as text, and whether that text is the whole workbook.
 *
 * The text carries the same `=== Truncated: … ===` line it always has, which
 * is what tells a model the extract is partial. `$truncation` says the same
 * thing to code: it is null only when every value the workbook holds is in
 * the text.
 */
final readonly class SpreadsheetConversion
{
    public function __construct(
        public string $text,
        public ?SpreadsheetTruncation $truncation = null,
    ) {}

    /** Whether every value in the workbook made it into the text. */
    public function isComplete(): bool
    {
        return $this->truncation === null;
    }

    /**
     * @return array{text: string, truncation: string|null}
     */
    public function toArray(): array
    {
        return ['text' => $this->text, 'truncation' => $this->truncation?->value];
    }

    /**
     * The inverse of {@see toArray()}: how an isolated conversion's result
     * crosses back from the child process.
     *
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! is_string($data['text'] ?? null)) {
            throw new \InvalidArgumentException('A spreadsheet conversion needs its text.');
        }

        $truncation = $data['truncation'] ?? null;
        if ($truncation !== null && ! is_string($truncation)) {
            throw new \InvalidArgumentException('A spreadsheet conversion\'s truncation must be a string or null.');
        }

        return new self($data['text'], $truncation === null ? null : SpreadsheetTruncation::from($truncation));
    }
}
