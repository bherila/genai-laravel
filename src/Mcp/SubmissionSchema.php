<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Opis\JsonSchema\Validator;

final class SubmissionSchema
{
    private const METASCHEMA = 'https://json-schema.org/draft/2020-12/schema';

    /** The vocabulary metaschemas the root metaschema references. */
    private const VOCABULARIES = ['core', 'applicator', 'unevaluated', 'validation', 'meta-data', 'format-annotation', 'content'];

    private ?Validator $metaschemaValidator = null;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function forPayload(array $payload): array
    {
        $tools = $payload['tools'] ?? [];
        $branches = array_map(fn (array $tool): array => [
            'type' => 'object',
            'properties' => [
                'name' => ['const' => $tool['name']],
                'input' => $this->wireSchema($tool['input_schema']),
                // Optional: an executor that has its own call id keeps it, so the
                // completion correlates with the executor's own records.
                'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            ],
            'required' => ['name', 'input'],
            'additionalProperties' => false,
        ], $tools);

        $choice = $payload['tool_choice']['type'] ?? 'auto';
        $minItems = in_array($choice, ['any', 'tool'], true) ? 1 : 0;
        $maxItems = $choice === 'none' ? 0 : (int) config('genai.mcp.limits.max_tool_calls', 16);
        if ($choice === 'tool') {
            $name = $payload['tool_choice']['name'] ?? '';
            $branches = array_values(array_filter($branches, static fn (array $branch): bool => ($branch['properties']['name']['const'] ?? null) === $name));
        }

        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'maxLength' => (int) config('genai.mcp.limits.max_completion_text_chars', 100000)],
                'tool_calls' => [
                    'type' => 'array', 'minItems' => $minItems, 'maxItems' => $maxItems,
                    'items' => $branches === [] ? false : ['oneOf' => $branches],
                ],
            ],
            'required' => in_array($choice, ['any', 'tool'], true) ? ['tool_calls'] : ($choice === 'none' ? ['text'] : []),
            'additionalProperties' => false,
        ];
        if ($choice === 'auto') {
            $schema['anyOf'] = [
                ['required' => ['text'], 'properties' => ['text' => ['minLength' => 1]]],
                ['required' => ['tool_calls'], 'properties' => ['tool_calls' => ['minItems' => 1]]],
            ];
        }
        if ($choice === 'none') {
            $schema['properties']['text']['minLength'] = 1;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $response, array $payload): void
    {
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > (int) config('genai.mcp.limits.max_completion_bytes', 1048576)) {
            throw new McpQueueException('Completion exceeds the configured byte limit.', 413);
        }
        $this->assertDepth($response, (int) config('genai.mcp.limits.max_json_nesting', 32));

        $schema = $this->forPayload($payload);
        $dataObject = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
        $schemaObject = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $result = (new Validator)->validate($dataObject, $schemaObject);
        if (! $result->isValid()) {
            throw new McpQueueException('Completion does not match submission_schema.', 422, [
                'keyword' => $result->error()?->keyword(),
            ]);
        }
    }

    /** @param array<string, mixed> $schema */
    public function assertPortable(array $schema): void
    {
        $this->assertNoReferences($schema);
        $this->assertWellFormed(json_encode($schema, JSON_THROW_ON_ERROR));
        $this->assertObjectShaped($schema);
    }

    /**
     * A queued tool call carries its arguments as a JSON object, and so does
     * the `GenAiResponse` a completion is turned back into. A scalar or list
     * input schema is legal JSON Schema but has nowhere to live in either, so
     * accepting one at enqueue means the completion validates against a schema
     * whose values then break assistantMessage(). Refuse it while the caller
     * is still there to hear about it.
     *
     * @param  array<string, mixed>  $schema
     */
    private function assertObjectShaped(array $schema): void
    {
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        if ($types !== ['object']) {
            throw new McpQueueException('Tool input schemas must declare an object type for queued execution.', 422);
        }
    }

    /**
     * Check a tool's input schema against the Draft 2020-12 metaschema before
     * the work is durably enqueued.
     *
     * `Schema::fromArray()` is an escape hatch, so a structurally invalid
     * schema — `required` given as a string, a `type` naming no JSON type —
     * otherwise reaches the queue intact and only surfaces much later, when an
     * executor's completion is validated against it. Validating the schema as
     * data reaches every nested subschema, which parsing it lazily does not.
     *
     * The metaschema and its vocabularies are bundled with the package and the
     * validator resolves nothing else, so no reference is ever retrieved over
     * the network.
     */
    private function assertWellFormed(string $encoded): void
    {
        $result = $this->metaschema()->validate(
            json_decode($encoded, false, 512, JSON_THROW_ON_ERROR),
            (object) ['$ref' => self::METASCHEMA],
        );
        if (! $result->isValid()) {
            throw new McpQueueException('Tool input schema is not a valid JSON Schema (Draft 2020-12).', 422, [
                'keyword' => $result->error()?->keyword(),
            ]);
        }
    }

    /** A validator that knows the bundled metaschema URIs and no others. */
    private function metaschema(): Validator
    {
        if ($this->metaschemaValidator !== null) {
            return $this->metaschemaValidator;
        }
        $validator = new Validator;
        $resolver = $validator->resolver();
        if ($resolver === null) {
            throw new \RuntimeException('The JSON Schema validator has no resolver to register the bundled metaschema with.');
        }
        $directory = dirname(__DIR__, 2).'/resources/json-schema/draft-2020-12';
        $resolver->registerRaw($this->read($directory.'/schema.json'), self::METASCHEMA);
        foreach (self::VOCABULARIES as $vocabulary) {
            $resolver->registerRaw($this->read($directory.'/meta/'.$vocabulary.'.json'), 'https://json-schema.org/draft/2020-12/meta/'.$vocabulary);
        }

        return $this->metaschemaValidator = $validator;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("The bundled JSON Schema metaschema [{$path}] could not be read.");
        }

        return $contents;
    }

    private function assertNoReferences(mixed $schema): void
    {
        if (is_object($schema)) {
            $schema = get_object_vars($schema);
        }
        if (! is_array($schema)) {
            return;
        }
        foreach ($schema as $key => $value) {
            if (in_array($key, ['$ref', '$dynamicRef', '$recursiveRef'], true)) {
                throw new McpQueueException('JSON Schema references are not supported for queued portable requests; inline the referenced schema.', 422);
            }
            $this->assertNoReferences($value);
        }
    }

    private function wireSchema(mixed $schema): mixed
    {
        if (is_object($schema)) {
            $schema = get_object_vars($schema);
        }
        if (! is_array($schema)) {
            return $schema;
        }
        foreach ($schema as $key => $value) {
            $schema[$key] = $this->wireSchema($value);
        }
        if (($schema['type'] ?? null) === 'object' && ($schema['properties'] ?? null) === []) {
            $schema['properties'] = new \stdClass;
        }

        return $schema;
    }

    private function assertDepth(mixed $value, int $remaining): void
    {
        if ($remaining < 0) {
            throw new McpQueueException('JSON nesting exceeds the configured limit.', 413);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $child) {
            $this->assertDepth($child, $remaining - 1);
        }
    }
}
