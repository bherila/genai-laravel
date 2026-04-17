<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic definition of a single callable tool / function.
 *
 * Clients translate this into their native tool spec format:
 *   Gemini  → function_declaration with UPPERCASE schema types
 *   Bedrock → toolSpec with JSON Schema
 *   Anthropic → tool with input_schema
 */
final class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly Schema $inputSchema,
    ) {}
}
