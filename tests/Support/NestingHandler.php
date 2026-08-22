<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdProvider;

/**
 * Runs a second middleware instance inside the first one's handler — the
 * "registered twice" shape — and records what the holder carries once the
 * inner instance is done but the outer one is still unwinding. That window is
 * where a reader sitting between the two layers lives: a response decorator on
 * the way out, an error-handling middleware on the way up.
 */
final class NestingHandler implements RequestHandlerInterface
{
    public ?string $holderAfterInnerFinished = null;

    public function __construct(
        private readonly MiddlewareInterface $inner,
        private readonly RequestHandlerInterface $handler,
        private readonly CorrelationIdProvider $holder,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->inner->process($request, $this->handler);
        } finally {
            // In `finally`, so the throwing path is observed too — that is the
            // path an error-handling middleware above would take.
            $this->holderAfterInnerFinished = $this->holder->tryGet();
        }
    }
}
