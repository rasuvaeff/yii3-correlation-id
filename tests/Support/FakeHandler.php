<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests\Support;

use Closure;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FakeHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $handledRequest = null;

    /**
     * @param Closure(ServerRequestInterface): void|null $spy Runs while the
     * request is still in flight — the place to observe the holder.
     */
    public function __construct(private readonly ?Closure $spy = null) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->handledRequest = $request;

        if ($this->spy instanceof \Closure) {
            ($this->spy)($request);
        }

        return new Response(200);
    }
}
