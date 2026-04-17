<?php

namespace Bherila\GenAiLaravel;

/**
 * Fluent builder helpers for AI tool / function-calling schemas.
 *
 * Supports both Gemini-style (UPPERCASE type strings) and JSON Schema style
 * (lowercase, used by Bedrock/Anthropic). Use the provider-specific methods
 * or the common JSON Schema methods depending on your target.
 *
 * Usage (Gemini — UPPERCASE types):
 *   use Bherila\GenAiLaravel\ToolDefinitionBuilder as Tdb;
 *   'price' => Tdb::number(),
 *   'name'  => Tdb::string(),
 *   'items' => Tdb::arrayOf(Tdb::object(['id' => Tdb::number()])),
 *
 * Usage (Bedrock/JSON Schema — lowercase types):
 *   'price' => Tdb::jsonNumber(),
 *   'name'  => Tdb::jsonString(),
 */
class ToolDefinitionBuilder
{
    // ── Gemini-style (UPPERCASE) ──────────────────────────────────────────────

    /** @return array{type: 'NUMBER'} */
    public static function number(): array
    {
        return ['type' => 'NUMBER'];
    }

    /** @return array{type: 'STRING'} */
    public static function string(): array
    {
        return ['type' => 'STRING'];
    }

    /** @return array{type: 'BOOLEAN'} */
    public static function boolean(): array
    {
        return ['type' => 'BOOLEAN'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  string[]  $required
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'OBJECT', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $itemSchema
     * @return array<string, mixed>
     */
    public static function arrayOf(array $itemSchema): array
    {
        return ['type' => 'ARRAY', 'items' => $itemSchema];
    }

    /**
     * Build a Gemini function definition wrapper.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  string[]  $required
     * @return array<string, mixed>
     */
    public static function functionDefinition(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'parameters' => self::object($properties, $required),
        ];
    }

    // ── JSON Schema style (lowercase, Bedrock / Anthropic) ───────────────────

    /** @return array{type: 'number'} */
    public static function jsonNumber(): array
    {
        return ['type' => 'number'];
    }

    /** @return array{type: 'string'} */
    public static function jsonString(): array
    {
        return ['type' => 'string'];
    }

    /** @return array{type: 'boolean'} */
    public static function jsonBoolean(): array
    {
        return ['type' => 'boolean'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  string[]  $required
     * @return array<string, mixed>
     */
    public static function jsonObject(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $itemSchema
     * @return array<string, mixed>
     */
    public static function jsonArrayOf(array $itemSchema): array
    {
        return ['type' => 'array', 'items' => $itemSchema];
    }

    /**
     * Build a Bedrock/JSON Schema tool spec wrapper.
     *
     * @param  array<string, mixed>  $inputSchema  JSON Schema for the tool parameters.
     * @return array<string, mixed>  Bedrock toolSpec shape.
     */
    public static function bedrockToolSpec(string $name, string $description, array $inputSchema): array
    {
        return [
            'toolSpec' => [
                'name' => $name,
                'description' => $description,
                'inputSchema' => ['json' => $inputSchema],
            ],
        ];
    }
}
