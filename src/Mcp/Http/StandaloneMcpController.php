<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Bherila\GenAiLaravel\Mcp\GenAiMcpServerFactory;
use Bherila\McpLaravelBridge\Http\McpHttpPolicy;
use Bherila\McpLaravelBridge\Http\SdkMiddlewareProfile;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class StandaloneMcpController
{
    public function __construct(
        private GenAiMcpServerFactory $servers,
        private StreamableHttpResponder $responder,
        private McpHttpPolicy $policy,
    ) {}

    public function __invoke(Request $request): Response
    {
        return $this->responder->run(
            $request,
            $this->servers->make($request),
            SdkMiddlewareProfile::forHardenedLaravelEdge(),
            $this->policy->maxRequestBodyBytes,
        );
    }
}
