<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Schema;
use PHPUnit\Framework\TestCase;

class SchemaTest extends TestCase
{
    public function test_a_parameterless_object_serializes_properties_as_an_empty_json_object(): void
    {
        $json = json_encode(Schema::object([])->jsonSerialize());

        // Anthropic rejects `"properties":[]` — it must be `{}`.
        $this->assertStringContainsString('"properties":{}', (string) $json);
        $this->assertStringNotContainsString('"properties":[]', (string) $json);
    }

    public function test_the_empty_object_escape_hatch_is_also_corrected(): void
    {
        $json = json_encode(Schema::fromArray(['type' => 'object', 'properties' => []])->jsonSerialize());

        $this->assertStringContainsString('"properties":{}', (string) $json);
        $this->assertStringNotContainsString('"properties":[]', (string) $json);
    }

    public function test_nested_empty_objects_are_corrected_recursively(): void
    {
        $schema = Schema::object(['meta' => Schema::object([])]);

        $json = json_encode($schema->jsonSerialize());

        // The outer object has one property; the nested one is empty → `{}`.
        $this->assertStringContainsString('"meta":{"type":"object","properties":{}}', (string) $json);
        $this->assertStringNotContainsString('[]', (string) $json);
    }

    public function test_populated_objects_and_arrays_are_unaffected(): void
    {
        $schema = Schema::object(
            ['name' => Schema::string(), 'tags' => Schema::arrayOf(Schema::string())],
            required: ['name'],
        );

        $json = (string) json_encode($schema->jsonSerialize());

        $this->assertStringContainsString('"properties":{"name":', $json);
        $this->assertStringContainsString('"required":["name"]', $json); // lists stay arrays
        $this->assertStringContainsString('"type":"array"', $json);
    }

    public function test_to_array_keeps_the_plain_array_view(): void
    {
        // toArray() is the raw view used by internal transforms; it does not
        // inject stdClass, so callers can still recurse over it as arrays.
        $this->assertSame(['type' => 'object', 'properties' => []], Schema::object([])->toArray());
    }
}
