<?php

namespace Bherila\GenAiLaravel;

/**
 * Immutable JSON Schema descriptor used in tool definitions.
 *
 * All types use standard JSON Schema (lowercase) — clients translate to
 * provider-native formats (e.g. Gemini UPPERCASE) internally.
 *
 * Usage:
 *   Schema::string('A person\'s name')
 *   Schema::object(['amount' => Schema::number(), 'date' => Schema::string()], required: ['amount'])
 *   Schema::arrayOf(Schema::string())
 *   Schema::enum(['pdf', 'csv', 'docx'], 'File format')
 *   Schema::fromArray(['type' => 'string', 'format' => 'date'])  // escape hatch
 */
final class Schema
{
    private function __construct(private readonly array $definition) {}

    public static function string(?string $description = null): self
    {
        return new self(self::base('string', $description));
    }

    public static function number(?string $description = null): self
    {
        return new self(self::base('number', $description));
    }

    public static function integer(?string $description = null): self
    {
        return new self(self::base('integer', $description));
    }

    public static function boolean(?string $description = null): self
    {
        return new self(self::base('boolean', $description));
    }

    /**
     * @param  array<string, self>  $properties  Map of property name → Schema.
     * @param  string[]  $required
     */
    public static function object(array $properties, array $required = [], ?string $description = null): self
    {
        $def = [
            'type' => 'object',
            'properties' => array_map(fn (self $s) => $s->toArray(), $properties),
        ];
        if ($required !== []) {
            $def['required'] = $required;
        }
        if ($description !== null) {
            $def['description'] = $description;
        }

        return new self($def);
    }

    public static function arrayOf(self $items, ?string $description = null): self
    {
        $def = ['type' => 'array', 'items' => $items->toArray()];
        if ($description !== null) {
            $def['description'] = $description;
        }

        return new self($def);
    }

    /** @param  string[]  $values */
    public static function enum(array $values, ?string $description = null): self
    {
        $def = ['type' => 'string', 'enum' => $values];
        if ($description !== null) {
            $def['description'] = $description;
        }

        return new self($def);
    }

    /** Escape hatch for complex schemas that can't be expressed via builder methods. */
    public static function fromArray(array $definition): self
    {
        return new self($definition);
    }

    public function toArray(): array
    {
        return $this->definition;
    }

    private static function base(string $type, ?string $description): array
    {
        $def = ['type' => $type];
        if ($description !== null) {
            $def['description'] = $description;
        }

        return $def;
    }
}
