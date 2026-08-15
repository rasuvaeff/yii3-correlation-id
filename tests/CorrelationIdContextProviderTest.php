<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\Tests\Support\RecordingTarget;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Log\ContextProvider\CompositeContextProvider;
use Yiisoft\Log\ContextProvider\SystemContextProvider;
use Yiisoft\Log\Logger;

#[Test]
#[Covers(CorrelationIdContextProvider::class)]
final class CorrelationIdContextProviderTest
{
    private CorrelationIdHolder $holder;
    private CorrelationIdContextProvider $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->holder = new CorrelationIdHolder();
        $this->fixture = new CorrelationIdContextProvider($this->holder);
    }

    public function exposesTheIdUnderTheDefaultKey(): void
    {
        $this->holder->set('id-1');

        Assert::same($this->fixture->getContext(), ['requestId' => 'id-1']);
    }

    public function exposesTheIdUnderTheConfiguredKey(): void
    {
        $this->holder->set('id-1');
        $provider = new CorrelationIdContextProvider($this->holder, contextKey: 'request_id');

        Assert::same($provider->getContext(), ['request_id' => 'id-1']);
    }

    public function returnsEmptyContextOutsideARequest(): void
    {
        Assert::same($this->fixture->getContext(), []);
    }

    public function reflectsTheHolderOnEveryCall(): void
    {
        $this->holder->set('id-1');
        Assert::same($this->fixture->getContext(), ['requestId' => 'id-1']);

        $this->holder->clear();
        Assert::same($this->fixture->getContext(), []);

        $this->holder->set('id-2');
        Assert::same($this->fixture->getContext(), ['requestId' => 'id-2']);
    }

    public function reachesLoggerContextWhenComposedWithTheSystemProvider(): void
    {
        $this->holder->set('id-1');
        $target = new RecordingTarget();
        $logger = new Logger(
            [$target],
            new CompositeContextProvider(new SystemContextProvider(), $this->fixture),
        );

        $logger->info('hello');
        $logger->flush(final: true);

        Assert::same($target->messages[0]->context('requestId'), 'id-1');
    }
}
