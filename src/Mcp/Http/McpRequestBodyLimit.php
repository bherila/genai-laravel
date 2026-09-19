<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses an oversized REST body before anything decodes it. The declared
 * Content-Length is checked first so an honest client is rejected without
 * reading the body; the actual length is checked too because a chunked
 * request declares none.
 */
final class McpRequestBodyLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('genai.mcp.rest.max_body_bytes', 1114112);
        $declared = $request->headers->get('Content-Length');
        if ((is_string($declared) && ctype_digit($declared) && (int) $declared > $limit)
            || strlen((string) $request->getContent()) > $limit) {
            return new JsonResponse(['message' => 'Request body exceeds the configured limit.'], 413);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
