<?php

namespace Bherila\GenAiLaravel\Mcp\Auth;

use Bherila\GenAiLaravel\Contracts\MailboxAccessResolver;
use Bherila\GenAiLaravel\Mcp\ExecutionContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class McpAuthenticate
{
    public function __construct(private MailboxAccessResolver $resolver) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }
        $context = $this->resolver->resolve($request);
        if ($context === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set(ExecutionContext::class, $context);

        return $next($request);
    }
}
