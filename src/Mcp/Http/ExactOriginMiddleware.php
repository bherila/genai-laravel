<?php

namespace Bherila\GenAiLaravel\Mcp\Http;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Rejects non-browser-equivalent Origin values before a tool handler executes. */
final readonly class ExactOriginMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private array $allowedOrigins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && ! in_array($origin, $this->allowedOrigins, true)) {
            return (new HttpFactory)->createResponse(403)->withHeader('Content-Type', 'text/plain')
                ->withBody((new HttpFactory)->createStream('Forbidden: Invalid Origin header.'));
        }

        return $handler->handle($request);
    }
}
