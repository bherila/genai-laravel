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
final class Schema implements \JsonSerializable
{
    /** @param  array<string, mixed>  $definition */
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

    /**
     * Escape hatch for complex schemas that can't be expressed via builder
     * methods.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        return new self($definition);
    }

    /**
     * The raw definition as a plain associative array. Object schemas keep their
     * `properties` map as an array here (empty when there are no properties); use
     * {@see jsonSerialize} for the wire-ready form that providers accept.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->definition;
    }

    /**
     * JSON-ready form. An object schema's `properties` is always encoded as a
     * JSON object — `{}` when empty — because providers such as Anthropic reject
     * an empty array (`tools.N.input_schema.properties: Input should be an
     * object`). Applied recursively so nested empty objects are also correct.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return self::objectSafe($this->definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private static function objectSafe(array $definition): array
    {
        foreach ($definition as $key => $value) {
            if (is_array($value)) {
                $definition[$key] = self::objectSafe($value);
            }
        }

        if (($definition['type'] ?? null) === 'object'
            && array_key_exists('properties', $definition)
            && $definition['properties'] === []) {
            $definition['properties'] = new \stdClass;
        }

        return $definition;
    }

    /** @return array<string, string> */
    private static function base(string $type, ?string $description): array
    {
        $def = ['type' => $type];
        if ($description !== null) {
            $def['description'] = $description;
        }

        return $def;
    }
}
