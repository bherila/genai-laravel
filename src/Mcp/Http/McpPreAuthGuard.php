<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
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
    public function __construct(private RateLimiter $limiter, private Router $router) {}

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

    /** Named REST routes in routes/mcp.php; the guard's only knowledge of which requests are the package's. */
    public const array REST_ROUTES = [
        'genai.mcp.queue.status', 'genai.mcp.claims.store', 'genai.mcp.requests.show', 'genai.mcp.requests.lease',
        'genai.mcp.requests.complete', 'genai.mcp.requests.fail', 'genai.mcp.attachments.show',
    ];

    public const string SERVER_ROUTE = 'genai.mcp.server';

    /**
     * The body cap for the package endpoint this request targets, or null for
     * any other route. Matched against the registered routes themselves rather
     * than re-derived from config, so any prefix or path the router accepts
     * (including an empty REST prefix) is guarded exactly as it is routed.
     */
    private function bodyLimit(Request $request): ?int
    {
        if (! (bool) config('genai.mcp.enabled', false)) {
            return null;
        }
        $routes = $this->router->getRoutes();
        if ($this->matchesAny($routes, $request, [self::SERVER_ROUTE])) {
            return (int) config('genai.mcp.server.max_body_bytes', 262144);
        }
        if ($this->matchesAny($routes, $request, self::REST_ROUTES)) {
            $configured = config('genai.mcp.rest.max_body_bytes');

            return is_numeric($configured)
                ? (int) $configured
                : 2 * (int) config('genai.mcp.limits.max_completion_bytes', 1048576) + 65536;
        }

        return null;
    }

    /** @param list<string> $names */
    private function matchesAny(RouteCollectionInterface $routes, Request $request, array $names): bool
    {
        foreach ($names as $name) {
            $route = $routes->getByName($name);
            // Method is ignored so a wrong-method request to a package path is still capped.
            if ($route instanceof Route && $route->matches($request, false)) {
                return true;
            }
        }

        return false;
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
