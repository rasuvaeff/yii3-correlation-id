<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use LogicException;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\Exception\CorrelationIdNotSetException;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(CorrelationIdHolder::class)]
#[Covers(CorrelationIdNotSetException::class)]
final class CorrelationIdHolderTest
{
    private CorrelationIdHolder $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->fixture = new CorrelationIdHolder();
    }

    public function returnsTheIdThatWasSet(): void
    {
        $this->fixture->set('id-1');

        Assert::same($this->fixture->get(), 'id-1');
    }

    public function rejectsASecondSetWithinTheSameRequest(): void
    {
        $this->fixture->set('id-1');

        Expect::exception(LogicException::class)->withMessageContaining('already set');

        $this->fixture->set('id-2');
    }

    public function getThrowsBeforeAnyIdIsSet(): void
    {
        Expect::exception(CorrelationIdNotSetException::class)->withMessageContaining('has not been set');

        $this->fixture->get();
    }

    public function tryGetReturnsNullBeforeAnyIdIsSet(): void
    {
        Assert::null($this->fixture->tryGet());
    }

    public function tryGetReturnsTheIdThatWasSet(): void
    {
        $this->fixture->set('id-1');

        Assert::same($this->fixture->tryGet(), 'id-1');
    }

    public function clearMakesGetThrowAgain(): void
    {
        $this->fixture->set('id-1');
        $this->fixture->clear();

        Expect::exception(CorrelationIdNotSetException::class);

        $this->fixture->get();
    }

    public function clearReopensSetForTheNextRequest(): void
    {
        $this->fixture->set('id-1');
        $this->fixture->clear();
        $this->fixture->set('id-2');

        Assert::same($this->fixture->get(), 'id-2');
    }

    public function overrideBypassesTheSetOnceRule(): void
    {
        $this->fixture->set('id-1');
        $this->fixture->override('id-2');

        Assert::same($this->fixture->get(), 'id-2');
    }

    public function overrideWorksOnAnEmptyHolder(): void
    {
        $this->fixture->override('id-1');

        Assert::same($this->fixture->get(), 'id-1');
    }

    public function rejectedSecondSetKeepsTheOriginalId(): void
    {
        $this->fixture->set('id-1');

        try {
            $this->fixture->set('id-2');
        } catch (LogicException) {
            // The holder must not be left half-written by the rejected call.
        }

        Assert::same($this->fixture->get(), 'id-1');
    }

    public function runWithReturnsCallbackResultAndClearsNewScope(): void
    {
        $result = $this->fixture->runWith('message-1', fn(): string => $this->fixture->get());

        Assert::same($result, 'message-1');
        Assert::null($this->fixture->tryGet());
    }

    public function runWithRestoresPreviousScope(): void
    {
        $this->fixture->set('request-1');

        $this->fixture->runWith('message-1', function (): void {
            Assert::same($this->fixture->get(), 'message-1');
        });

        Assert::same($this->fixture->get(), 'request-1');
    }

    public function nestedRunWithRestoresEachScope(): void
    {
        $seen = [];

        $this->fixture->runWith('outer', function () use (&$seen): void {
            $seen[] = $this->fixture->get();
            $this->fixture->runWith('inner', function () use (&$seen): void {
                $seen[] = $this->fixture->get();
            });
            $seen[] = $this->fixture->get();
        });

        Assert::same($seen, ['outer', 'inner', 'outer']);
        Assert::null($this->fixture->tryGet());
    }

    public function runWithRestoresScopeWhenCallbackThrows(): void
    {
        $this->fixture->set('request-1');

        try {
            $this->fixture->runWith('message-1', static function (): never {
                throw new RuntimeException('failure');
            });
        } catch (RuntimeException) {
            // The outer request scope must survive a failed nested operation.
        }

        Assert::same($this->fixture->get(), 'request-1');
    }
}
