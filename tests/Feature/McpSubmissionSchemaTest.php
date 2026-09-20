<?php

namespace Bherila\GenAiLaravel\Tests\Feature;

use Bherila\GenAiLaravel\GenAiServiceProvider;
use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Bherila\GenAiLaravel\Mcp\SubmissionSchema;
use Bherila\GenAiLaravel\Schema;
use Orchestra\Testbench\TestCase;

/**
 * A queued tool schema is durable: it outlives the request that supplied it and
 * is what an executor's completion is checked against, possibly days later and
 * in another process. Everything wrong with it has to be caught here, while the
 * caller is still on the line, rather than when a completion arrives.
 */
final class McpSubmissionSchemaTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GenAiServiceProvider::class];
    }

    public function test_a_well_formed_object_schema_is_accepted(): void
    {
        $this->schemas()->assertPortable(Schema::object([
            'amount' => Schema::number('Total due'),
            'lines' => Schema::arrayOf(Schema::object(['sku' => Schema::string()], ['sku'])),
        ], ['amount'])->jsonSerialize());

        $this->addToAssertionCount(1);
    }

    public function test_structurally_invalid_keywords_are_rejected(): void
    {
        $invalid = [
            'required as a string' => ['type' => 'object', 'required' => 'amount'],
            'type as a number' => ['type' => 3],
            'type naming no json type' => ['type' => 'objekt'],
            'properties as a string' => ['type' => 'object', 'properties' => 'nope'],
            'minLength as a word' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'minLength' => 'five']]],
            'nested required as a string' => ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'required' => 'x']]],
            'deeply nested bad minimum' => ['type' => 'object', 'properties' => [
                'a' => ['type' => 'array', 'items' => ['type' => 'number', 'minimum' => 'nope']],
            ]],
        ];

        foreach ($invalid as $label => $schema) {
            try {
                $this->schemas()->assertPortable(Schema::fromArray($schema)->jsonSerialize());
                $this->fail("Expected [{$label}] to be rejected.");
            } catch (McpQueueException $exception) {
                $this->assertSame(422, $exception->httpStatus, $label);
            }
        }
    }

    public function test_non_object_input_schemas_are_rejected(): void
    {
        $invalid = [
            'string' => ['type' => 'string'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'null' => ['type' => 'null'],
            'union including object' => ['type' => ['object', 'null']],
            'no type at all' => ['description' => 'anything goes'],
        ];

        foreach ($invalid as $label => $schema) {
            try {
                $this->schemas()->assertPortable(Schema::fromArray($schema)->jsonSerialize());
                $this->fail("Expected the [{$label}] input schema to be rejected.");
            } catch (McpQueueException $exception) {
                $this->assertSame(422, $exception->httpStatus, $label);
            }
        }
    }

    public function test_references_are_still_rejected_before_anything_is_resolved(): void
    {
        $this->expectException(McpQueueException::class);
        $this->schemas()->assertPortable(Schema::fromArray([
            'type' => 'object',
            'properties' => ['a' => ['$ref' => 'https://example.test/schema.json']],
        ])->jsonSerialize());
    }

    private function schemas(): SubmissionSchema
    {
        return $this->app->make(SubmissionSchema::class);
    }
}
