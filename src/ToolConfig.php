<?php

namespace Bherila\GenAiLaravel;

/**
 * Provider-agnostic container for tool definitions and selection strategy.
 *
 * Pass to converse(), converseWithFileRef(), or converseWithInlineFile().
 * Each client translates this into its native toolConfig format.
 */
final class ToolConfig
{
    public readonly ToolChoice $choice;

    /**
     * @param  list<ToolDefinition>  $tools
     * @param  ToolChoice|null  $choice  Defaults to ToolChoice::auto() when null.
     */
    public function __construct(
        public readonly array $tools,
        ?ToolChoice $choice = null,
    ) {
        $this->choice = $choice ?? ToolChoice::auto();
    }
}
