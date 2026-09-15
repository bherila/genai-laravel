<?php

namespace Bherila\GenAiLaravel\Mcp;

use Bherila\GenAiLaravel\Mcp\Exceptions\McpQueueException;
use Opis\JsonSchema\Validator;

final class SubmissionSchema
{
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
        json_encode($schema, JSON_THROW_ON_ERROR);
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
