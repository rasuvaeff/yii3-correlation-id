<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use Psr\Http\Message\RequestInterface;

/**
 * Propagates the current correlation ID into an outgoing PSR-7 request.
 *
 * @api
 */
final readonly class CorrelationIdHeaderInjector
{
    public function __construct(
        private CorrelationIdProvider $provider,
        private string $headerName = 'X-Request-ID',
    ) {}

    public function inject(RequestInterface $request): RequestInterface
    {
        $id = $this->provider->tryGet();

        return $id === null ? $request : $request->withHeader($this->headerName, $id);
    }
}
