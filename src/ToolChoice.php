<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic tool-selection strategy.
 *
 * Provider mappings:
 *   auto()        — Gemini AUTO  | Bedrock auto:{}   | Anthropic {type:"auto"}
 *   any()         — Gemini ANY   | Bedrock any:{}    | Anthropic {type:"any"}
 *   none()        — Gemini NONE  | Bedrock (omit)    | Anthropic {type:"none"}
 *   tool($name)   — Gemini ANY + allowedFunctionNames:[$name]
 *                   Bedrock tool:{name:$name}
 *                   Anthropic {type:"tool", name:$name}
 */
final class ToolChoice
{
    public const AUTO = 'auto';

    public const ANY = 'any';

    public const NONE = 'none';

    public const TOOL = 'tool';

    private function __construct(
        public readonly string $type,
        public readonly ?string $toolName = null,
    ) {}

    public static function auto(): self
    {
        return new self(self::AUTO);
    }

    public static function any(): self
    {
        return new self(self::ANY);
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** Force the model to call a specific named tool. */
    public static function tool(string $name): self
    {
        return new self(self::TOOL, $name);
    }
}
