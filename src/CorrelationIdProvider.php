<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use Rasuvaeff\Yii3CorrelationId\Exception\CorrelationIdNotSetException;

/**
 * Read-only access to the correlation ID in the current execution scope.
 *
 * @api
 */
interface CorrelationIdProvider
{
    /**
     * @throws CorrelationIdNotSetException When no ID has been set.
     */
    public function get(): string;

    public function tryGet(): ?string;
}
