<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use InvalidArgumentException;
use LogicException;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\Exception\CorrelationIdNotSetException;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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

    #[DataProvider('invalidIdProvider')]
    public function setRejectsAnInvalidId(string $id, string $messageFragment): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($messageFragment);

        $this->fixture->set($id);
    }

    #[DataProvider('invalidIdProvider')]
    public function overrideRejectsAnInvalidId(string $id, string $messageFragment): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($messageFragment);

        $this->fixture->override($id);
    }

    #[DataProvider('invalidIdProvider')]
    public function runWithRejectsAnInvalidId(string $id, string $messageFragment): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($messageFragment);

        $this->fixture->runWith($id, static fn(): string => 'never reached');
    }

    /**
     * The write paths a queue or console consumer reaches with an ID that
     * started life as an untrusted header on another service. From the holder
     * it goes verbatim into every log line and every outgoing request header.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIdProvider(): iterable
    {
        yield 'empty' => ['', 'must not be empty'];
        yield 'over the length limit' => [str_repeat('a', 4097), 'must not exceed 4096 bytes'];
        yield 'line feed' => ["id-1\n", 'control characters'];
        yield 'carriage return' => ["id-1\r", 'control characters'];
        yield 'header injection attempt' => ["id-1\r\nX-Evil: 1", 'control characters'];
        yield 'nul byte' => ["id-1\x00", 'control characters'];
        yield 'tab' => ["id\t1", 'control characters'];
        yield 'ansi escape' => ["\x1B[31mid-1", 'control characters'];
        yield 'lowest control byte' => ["\x01", 'control characters'];
        yield 'highest control byte in the low range' => ["id-1\x1F", 'control characters'];
        yield 'delete' => ["id-1\x7F", 'control characters'];
    }

    /**
     * The characters immediately outside the rejected class, so the guard is
     * pinned to exactly `[\x00-\x1F\x7F]` and not to something wider.
     *
     * @return iterable<string, array{string}>
     */
    public static function validIdProvider(): iterable
    {
        yield 'canonical uuid v4' => ['aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee'];
        yield 'single character' => ['a'];
        yield 'space' => ['id 1'];
        yield 'tilde' => ['id~1'];
        yield 'byte just above the delete character' => ["id-1\x80"];
        yield 'exactly at the length limit' => [str_repeat('a', 4096)];
    }

    #[DataProvider('validIdProvider')]
    public function setAcceptsAValidId(string $id): void
    {
        $this->fixture->set($id);

        Assert::same($this->fixture->get(), $id);
    }

    #[DataProvider('validIdProvider')]
    public function overrideAcceptsAValidId(string $id): void
    {
        $this->fixture->override($id);

        Assert::same($this->fixture->get(), $id);
    }

    #[DataProvider('validIdProvider')]
    public function runWithAcceptsAValidId(string $id): void
    {
        $seen = $this->fixture->runWith($id, fn(): string => $this->fixture->get());

        Assert::same($seen, $id);
    }

    public function setRejectsAnInvalidIdBeforeTouchingTheScope(): void
    {
        try {
            $this->fixture->set("poison\n");
        } catch (InvalidArgumentException) {
            // The rejected call must not have written anything.
        }

        Assert::null($this->fixture->tryGet());
    }

    public function overrideRejectsAnInvalidIdBeforeTouchingTheScope(): void
    {
        $this->fixture->set('request-1');

        try {
            $this->fixture->override("poison\n");
        } catch (InvalidArgumentException) {
            // The rejected call must leave the live scope alone.
        }

        Assert::same($this->fixture->get(), 'request-1');
    }

    /**
     * `runWith()` validates before it swaps the scope, so a rejected call
     * cannot leave the holder half-written for its own `finally` to restore
     * from — and the callback never runs.
     */
    public function runWithRejectsAnInvalidIdBeforeTouchingTheScope(): void
    {
        $this->fixture->set('request-1');
        $ran = false;

        try {
            $this->fixture->runWith("poison\n", static function () use (&$ran): void {
                $ran = true;
            });
        } catch (InvalidArgumentException) {
            // The outer request scope must survive a rejected nested scope.
        }

        Assert::false($ran);
        Assert::same($this->fixture->get(), 'request-1');
    }

    /**
     * The set-once rule is a contract about the holder's state; validity is a
     * contract about the argument. The argument is checked first, so an
     * invalid ID is reported as invalid rather than as a duplicate.
     */
    public function setReportsAnInvalidIdEvenWhenAnIdIsAlreadySet(): void
    {
        $this->fixture->set('request-1');

        Expect::exception(InvalidArgumentException::class)->withMessageContaining('control characters');

        $this->fixture->set("poison\n");
    }
}
