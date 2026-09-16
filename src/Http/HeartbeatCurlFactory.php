<?php

namespace Bherila\GenAiLaravel\Http;

use Closure;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/** Install cancellation-capable progress on the owned transport handle. */
final class HeartbeatCurlFactory implements CurlFactoryInterface
{
    private CurlFactory $factory;

    /** @param Closure():int $progress */
    public function __construct(private readonly Closure $progress)
    {
        $this->factory = new CurlFactory(3);
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        $easy = $this->factory->create($request, $options);
        curl_setopt($easy->handle, CURLOPT_NOPROGRESS, false);
        curl_setopt($easy->handle, CURLOPT_XFERINFOFUNCTION, $this->progress);

        return $easy;
    }

    public function release(EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
