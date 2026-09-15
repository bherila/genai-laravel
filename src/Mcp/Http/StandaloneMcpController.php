<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Bherila\GenAiLaravel\Mcp\GenAiMcpServerFactory;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Symfony\Component\HttpFoundation\Response;

final readonly class StandaloneMcpController
{
    public function __construct(private GenAiMcpServerFactory $servers, private StreamableHttpResponder $responder) {}

    public function __invoke(Request $request): Response
    {
        return $this->responder->run($request, $this->servers->make($request), [
            new ExactOriginMiddleware(array_values(config('genai.mcp.server.allowed_origins', []))),
            new CorsMiddleware(allowedOrigins: array_values(config('genai.mcp.server.allowed_origins', []))),
            new DnsRebindingProtectionMiddleware(allowedHosts: array_values(config('genai.mcp.server.allowed_hosts', []))),
        ], (int) config('genai.mcp.server.max_body_bytes', 262144));
    }
}
