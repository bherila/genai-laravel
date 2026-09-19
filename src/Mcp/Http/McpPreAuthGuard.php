<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP limit and body cap for the package's MCP endpoints, run as global
 * middleware directly after TrustProxies. Route middleware is too late for
 * both: global input transformers (TrimStrings, ConvertEmptyStringsToNull)
 * decode a JSON body before any route middleware runs, and the router's
 * middleware priority sorts a host's `auth:*` ahead of any `throttle:`, so an
 * invalid-token flood would be authenticated before it was counted.
 */
final readonly class McpPreAuthGuard
{
    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bodyLimit = $this->bodyLimit($request);
        if ($bodyLimit === null) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        // Keyed by client IP only: a token fingerprint would let an attacker
        // reset the limit by rotating tokens.
        $key = 'genai-mcp-preauth:'.hash('sha256', (string) $request->ip());
        if ($this->limiter->tooManyAttempts($key, (int) config('genai.mcp.rest.preauth_requests_per_minute', 300))) {
            return new JsonResponse(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => (string) $this->limiter->availableIn($key)]);
        }
        $this->limiter->hit($key, 60);

        if ($this->exceeds($request, $bodyLimit)) {
            return new JsonResponse(['message' => 'Request body exceeds the configured limit.'], 413);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /** The body cap for the package endpoint this request targets, or null for any other route. */
    private function bodyLimit(Request $request): ?int
    {
        if (! (bool) config('genai.mcp.enabled', false)) {
            return null;
        }
        $path = trim($request->path(), '/');
        $prefix = trim((string) config('genai.mcp.rest.prefix', 'genai/mcp/v1'), '/');
        if ((bool) config('genai.mcp.rest.enabled', true) && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
            return (int) config('genai.mcp.rest.max_body_bytes', 1114112);
        }
        if ((bool) config('genai.mcp.server.enabled', false) && $path === trim((string) config('genai.mcp.server.path', 'genai/mcp'), '/')) {
            return (int) config('genai.mcp.server.max_body_bytes', 262144);
        }

        return null;
    }

    /**
     * A declared length over the cap is refused unread. Otherwise read at most
     * one byte past the cap: a chunked or understated body must not be
     * buffered whole to find out it is too large. php://input can be re-read,
     * so the application still sees the full body.
     */
    private function exceeds(Request $request, int $limit): bool
    {
        $declared = $request->headers->get('Content-Length');
        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $limit) {
            return true;
        }
        $stream = $request->getContent(true);
        $read = stream_get_contents($stream, $limit + 1);
        rewind($stream);

        return is_string($read) && strlen($read) > $limit;
    }
}
