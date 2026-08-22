<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use InvalidArgumentException;
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
 * Every write path validates: the ID a queue consumer passes to `runWith()`
 * usually started life as an untrusted HTTP header on another service, and from
 * here it reaches every log line and every outgoing request header verbatim.
 *
 * @api
 */
final class CorrelationIdHolder implements CorrelationIdProvider
{
    /**
     * A sanity ceiling against log bloat and memory pressure, not a format
     * check — deliberately far above any `maxLength` the middleware would
     * plausibly be configured with (its own default is 128), so a legitimate
     * custom format never has the holder reject what the middleware accepted.
     */
    private const int MAX_ID_LENGTH = 4096;

    /**
     * The same class the middleware rejects in incoming and generated IDs.
     * Duplicated rather than shared: each `@api` entry point cleans its own
     * input, and this one is reachable without the middleware ever running.
     */
    private const string CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private ?string $id = null;

    /**
     * @throws InvalidArgumentException When the ID is empty, longer than 4096
     * bytes, or contains a control character.
     * @throws LogicException When an ID is already set for the current request.
     */
    public function set(string $id): void
    {
        $this->validate($id);

        if ($this->id !== null) {
            throw new LogicException('Correlation ID is already set for the current request; use override() to replace it');
        }

        $this->id = $id;
    }

    /**
     * Explicit replacement for console/test contexts where one process handles
     * several correlation scopes. Bypasses the set-once rule.
     *
     * @throws InvalidArgumentException When the ID is empty, longer than 4096
     * bytes, or contains a control character.
     */
    public function override(string $id): void
    {
        $this->validate($id);

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
     *
     * @throws InvalidArgumentException When the ID is empty, longer than 4096
     * bytes, or contains a control character. Thrown before the current scope
     * is touched, so a rejected call leaves the holder exactly as it was and
     * the callback never runs.
     */
    public function runWith(string $id, callable $callback): mixed
    {
        $this->validate($id);

        $previous = $this->id;
        $this->id = $id;

        try {
            return $callback();
        } finally {
            $this->id = $previous;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validate(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Correlation ID must not be empty');
        }

        $length = strlen($id);
        $limit = self::MAX_ID_LENGTH;

        if ($length > $limit) {
            throw new InvalidArgumentException("Correlation ID must not exceed {$limit} bytes, got {$length}");
        }

        // Control characters would be replayed verbatim by every reader: the
        // log context provider writes them into log lines a terminal may
        // interpret as ANSI escapes, and the outgoing header injector puts
        // them on the wire, where a CR or LF is header injection unless the
        // PSR-7 implementation happens to reject it (the specification does
        // not require it to).
        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $id) === 1) {
            throw new InvalidArgumentException('Correlation ID must not contain control characters');
        }
    }
}
