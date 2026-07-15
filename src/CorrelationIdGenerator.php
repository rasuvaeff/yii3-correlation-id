<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

/**
 * Produces a fresh correlation ID for requests that arrive without an
 * acceptable one. Implement it to emit a different ID format (ULID, opaque
 * token) and adjust the middleware validation pattern and maximum length to
 * match. The middleware rejects generated values that violate either setting.
 *
 * @api
 */
interface CorrelationIdGenerator
{
    public function generate(): string;
}
