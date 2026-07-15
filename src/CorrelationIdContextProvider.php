<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId;

use Yiisoft\Log\ContextProvider\ContextProviderInterface;

/**
 * Adds the current correlation ID to the context of every log message written
 * through `Yiisoft\Log\Logger`. Compose it with the logger's own
 * `SystemContextProvider` via `CompositeContextProvider` — see the README.
 *
 * Outside a request (console, worker bootstrap) no ID is set and the context
 * stays empty instead of failing.
 *
 * @api
 */
final readonly class CorrelationIdContextProvider implements ContextProviderInterface
{
    public function __construct(
        private CorrelationIdProvider $provider,
        private string $contextKey = 'requestId',
    ) {}

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function getContext(): array
    {
        $id = $this->provider->tryGet();

        return $id === null ? [] : [$this->contextKey => $id];
    }
}
