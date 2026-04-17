<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\ContentBlock;
use Bherila\GenAiLaravel\Schema;
use Bherila\GenAiLaravel\ToolChoice;
use Bherila\GenAiLaravel\ToolConfig;
use Bherila\GenAiLaravel\ToolDefinition;
use PHPUnit\Framework\TestCase;

class TypedApiTest extends TestCase
{
    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_schema_string_produces_json_schema(): void
    {
        $this->assertSame(['type' => 'string'], Schema::string()->toArray());
    }

    public function test_schema_string_with_description(): void
    {
        $s = Schema::string('A name');
        $this->assertSame(['type' => 'string', 'description' => 'A name'], $s->toArray());
    }

    public function test_schema_number(): void
    {
        $this->assertSame(['type' => 'number'], Schema::number()->toArray());
    }

    public function test_schema_integer(): void
    {
        $this->assertSame(['type' => 'integer'], Schema::integer()->toArray());
    }

    public function test_schema_boolean(): void
    {
        $this->assertSame(['type' => 'boolean'], Schema::boolean()->toArray());
    }

    public function test_schema_object_with_properties(): void
    {
        $s = Schema::object([
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ], required: ['name']);

        $arr = $s->toArray();
        $this->assertSame('object', $arr['type']);
        $this->assertSame(['type' => 'string'], $arr['properties']['name']);
        $this->assertSame(['type' => 'integer'], $arr['properties']['age']);
        $this->assertSame(['name'], $arr['required']);
    }

    public function test_schema_object_omits_required_when_empty(): void
    {
        $s = Schema::object(['x' => Schema::number()]);
        $this->assertArrayNotHasKey('required', $s->toArray());
    }

    public function test_schema_array_of(): void
    {
        $s = Schema::arrayOf(Schema::string());
        $arr = $s->toArray();
        $this->assertSame('array', $arr['type']);
        $this->assertSame(['type' => 'string'], $arr['items']);
    }

    public function test_schema_enum(): void
    {
        $s = Schema::enum(['a', 'b', 'c'], 'Choose one');
        $arr = $s->toArray();
        $this->assertSame('string', $arr['type']);
        $this->assertSame(['a', 'b', 'c'], $arr['enum']);
        $this->assertSame('Choose one', $arr['description']);
    }

    public function test_schema_from_array(): void
    {
        $raw = ['type' => 'string', 'format' => 'date'];
        $this->assertSame($raw, Schema::fromArray($raw)->toArray());
    }

    // ── ToolDefinition ────────────────────────────────────────────────────────

    public function test_tool_definition_stores_fields(): void
    {
        $t = new ToolDefinition('extract', 'Extract data', Schema::object(['x' => Schema::number()]));
        $this->assertSame('extract', $t->name);
        $this->assertSame('Extract data', $t->description);
        $this->assertSame('object', $t->inputSchema->toArray()['type']);
    }

    // ── ToolChoice ────────────────────────────────────────────────────────────

    public function test_tool_choice_auto(): void
    {
        $c = ToolChoice::auto();
        $this->assertSame(ToolChoice::AUTO, $c->type);
        $this->assertNull($c->toolName);
    }

    public function test_tool_choice_any(): void
    {
        $this->assertSame(ToolChoice::ANY, ToolChoice::any()->type);
    }

    public function test_tool_choice_none(): void
    {
        $this->assertSame(ToolChoice::NONE, ToolChoice::none()->type);
    }

    public function test_tool_choice_specific_tool(): void
    {
        $c = ToolChoice::tool('my_fn');
        $this->assertSame(ToolChoice::TOOL, $c->type);
        $this->assertSame('my_fn', $c->toolName);
    }

    // ── ToolConfig ────────────────────────────────────────────────────────────

    public function test_tool_config_defaults_to_auto_choice(): void
    {
        $cfg = new ToolConfig(tools: []);
        $this->assertSame(ToolChoice::AUTO, $cfg->choice->type);
    }

    public function test_tool_config_uses_provided_choice(): void
    {
        $cfg = new ToolConfig(tools: [], choice: ToolChoice::any());
        $this->assertSame(ToolChoice::ANY, $cfg->choice->type);
    }

    public function test_tool_config_stores_tool_definitions(): void
    {
        $t = new ToolDefinition('fn', 'desc', Schema::string());
        $cfg = new ToolConfig([$t]);
        $this->assertCount(1, $cfg->tools);
        $this->assertSame('fn', $cfg->tools[0]->name);
    }

    // ── ContentBlock ──────────────────────────────────────────────────────────

    public function test_content_block_text(): void
    {
        $b = ContentBlock::text('Hello');
        $this->assertSame('text', $b->type);
        $this->assertSame('Hello', $b->text);
        $this->assertNull($b->base64);
        $this->assertNull($b->mimeType);
    }

    public function test_content_block_document(): void
    {
        $b = ContentBlock::document('abc123', 'application/pdf');
        $this->assertSame('document', $b->type);
        $this->assertSame('abc123', $b->base64);
        $this->assertSame('application/pdf', $b->mimeType);
        $this->assertNull($b->text);
    }
}
