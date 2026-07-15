<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Exception;

use RuntimeException;

/**
 * Thrown when the correlation ID is read before the middleware set it — a
 * console command, a queue worker, or a middleware ordering mistake.
 *
 * @api
 */
final class CorrelationIdNotSetException extends RuntimeException {}
