<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Accepts every incoming ID that passed format and length validation.
 *
 * @api
 */
final readonly class AcceptAllIncomingCorrelationIdPolicy implements IncomingCorrelationIdPolicy
{
    #[\Override]
    public function accepts(ServerRequestInterface $request, string $id): bool
    {
        return true;
    }
}
