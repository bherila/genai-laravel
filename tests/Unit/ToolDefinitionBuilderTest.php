<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\ToolDefinitionBuilder as Tdb;
use PHPUnit\Framework\TestCase;

class ToolDefinitionBuilderTest extends TestCase
{
    // ── Gemini-style (UPPERCASE) ──────────────────────────────────────────────

    public function test_number_returns_uppercase_type(): void
    {
        $this->assertSame(['type' => 'NUMBER'], Tdb::number());
    }

    public function test_string_returns_uppercase_type(): void
    {
        $this->assertSame(['type' => 'STRING'], Tdb::string());
    }

    public function test_boolean_returns_uppercase_type(): void
    {
        $this->assertSame(['type' => 'BOOLEAN'], Tdb::boolean());
    }

    public function test_object_includes_properties(): void
    {
        $schema = Tdb::object(['name' => Tdb::string(), 'age' => Tdb::number()]);
        $this->assertSame('OBJECT', $schema['type']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('age', $schema['properties']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function test_object_includes_required_when_provided(): void
    {
        $schema = Tdb::object(['name' => Tdb::string()], ['name']);
        $this->assertSame(['name'], $schema['required']);
    }

    public function test_array_of_wraps_item_schema(): void
    {
        $schema = Tdb::arrayOf(Tdb::string());
        $this->assertSame('ARRAY', $schema['type']);
        $this->assertSame(['type' => 'STRING'], $schema['items']);
    }

    public function test_function_definition_produces_correct_shape(): void
    {
        $def = Tdb::functionDefinition('my_fn', 'Does something', [
            'amount' => Tdb::number(),
        ], ['amount']);

        $this->assertSame('my_fn', $def['name']);
        $this->assertSame('Does something', $def['description']);
        $this->assertSame('OBJECT', $def['parameters']['type']);
        $this->assertSame(['amount'], $def['parameters']['required']);
    }

    // ── JSON Schema (lowercase, Bedrock/Anthropic) ────────────────────────────

    public function test_json_number_returns_lowercase_type(): void
    {
        $this->assertSame(['type' => 'number'], Tdb::jsonNumber());
    }

    public function test_json_string_returns_lowercase_type(): void
    {
        $this->assertSame(['type' => 'string'], Tdb::jsonString());
    }

    public function test_json_boolean_returns_lowercase_type(): void
    {
        $this->assertSame(['type' => 'boolean'], Tdb::jsonBoolean());
    }

    public function test_json_object_includes_properties_and_required(): void
    {
        $schema = Tdb::jsonObject(['title' => Tdb::jsonString()], ['title']);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertSame(['title'], $schema['required']);
    }

    public function test_json_array_of_wraps_item_schema(): void
    {
        $schema = Tdb::jsonArrayOf(Tdb::jsonNumber());
        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'number'], $schema['items']);
    }

    public function test_bedrock_tool_spec_produces_correct_shape(): void
    {
        $schema = ['type' => 'object', 'properties' => ['x' => ['type' => 'number']]];
        $spec = Tdb::bedrockToolSpec('extract_data', 'Extract things', $schema);

        $this->assertSame('extract_data', $spec['toolSpec']['name']);
        $this->assertSame('Extract things', $spec['toolSpec']['description']);
        $this->assertSame($schema, $spec['toolSpec']['inputSchema']['json']);
    }

    // ── nested composition ────────────────────────────────────────────────────

    public function test_nested_object_in_array(): void
    {
        $schema = Tdb::arrayOf(
            Tdb::object(['id' => Tdb::number(), 'name' => Tdb::string()], ['id'])
        );

        $this->assertSame('ARRAY', $schema['type']);
        $this->assertSame('OBJECT', $schema['items']['type']);
        $this->assertSame(['id'], $schema['items']['required']);
    }
}
