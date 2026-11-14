<?php

namespace Bherila\GenAiLaravel\Tests\Unit;

use Bherila\GenAiLaravel\Mcp\GenAiMcpToolCatalog;
use PHPUnit\Framework\TestCase;

/**
 * A client reads the server instructions at the start of every session and discards text that reads
 * as standing orders, so they say what the mailbox tools are and never what to do next.
 */
class McpServerInstructionsTest extends TestCase
{
    public function test_instructions_describe_every_mailbox_tool_without_giving_the_client_a_work_loop(): void
    {
        $instructions = (new GenAiMcpToolCatalog)->instructions();

        foreach (['genai_queue_status', 'claim_genai_request', 'renew_genai_lease', 'complete_genai_request', 'fail_genai_request'] as $tool) {
            $this->assertStringContainsString($tool, $instructions);
        }
        foreach (['Repeat until', 'Claim one request at a time', 'Use the ', 'never invent', 'with your selected model'] as $order) {
            $this->assertStringNotContainsString($order, $instructions, "The instructions give the client an order: {$order}");
        }
    }
}
