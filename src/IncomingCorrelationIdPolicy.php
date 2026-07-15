<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides whether a valid incoming correlation ID is trusted for this request.
 * Format and length validation happen before this policy is called.
 *
 * @api
 */
interface IncomingCorrelationIdPolicy
{
    public function accepts(ServerRequestInterface $request, string $id): bool;
}
