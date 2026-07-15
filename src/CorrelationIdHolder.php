<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use LogicException;
use Rasuvaeff\Yii3CorrelationId\Exception\CorrelationIdNotSetException;

/**
 * Set-once holder for the correlation ID of the current request. The
 * middleware writes it; anything else (log context provider, audit trail,
 * problem details) reads it without threading the ID through arguments.
 *
 * A DI singleton, deliberately mutable: one ID per request lifecycle. The
 * middleware clears it in a `finally` block so long-lived workers do not leak
 * an ID into the next request.
 *
 * @api
 */
final class CorrelationIdHolder implements CorrelationIdProvider
{
    private ?string $id = null;

    /**
     * @throws LogicException When an ID is already set for the current request.
     */
    public function set(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Correlation ID is already set for the current request; use override() to replace it');
        }

        $this->id = $id;
    }

    /**
     * Explicit replacement for console/test contexts where one process handles
     * several correlation scopes. Bypasses the set-once rule.
     */
    public function override(string $id): void
    {
        $this->id = $id;
    }

    /**
     * @throws CorrelationIdNotSetException When no ID has been set.
     */
    #[\Override]
    public function get(): string
    {
        return $this->id ?? throw new CorrelationIdNotSetException('Correlation ID has not been set for the current request');
    }

    #[\Override]
    public function tryGet(): ?string
    {
        return $this->id;
    }

    public function clear(): void
    {
        $this->id = null;
    }

    /**
     * Runs a callback in an explicit correlation scope and restores the
     * previous scope even when the callback throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function runWith(string $id, callable $callback): mixed
    {
        $previous = $this->id;
        $this->id = $id;

        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }
}
