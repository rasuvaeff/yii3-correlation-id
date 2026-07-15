<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\Tests\Support\FakeHandler;
use Rasuvaeff\Yii3CorrelationId\Tests\Support\FixedGenerator;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;
use RuntimeException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use UnexpectedValueException;

#[Test]
#[Covers(CorrelationIdMiddleware::class)]
final class CorrelationIdMiddlewareTest
{
    private const string GENERATED_ID = '11111111-2222-4333-8444-555555555555';
    private const string INCOMING_ID = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';

    private CorrelationIdHolder $holder;
    private FixedGenerator $generator;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->holder = new CorrelationIdHolder();
        $this->generator = new FixedGenerator(self::GENERATED_ID);
    }

    public function reusesAcceptableIncomingId(): void
    {
        $response = $this->middleware()->process($this->request(self::INCOMING_ID), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
        Assert::same($this->generator->calls, 0);
    }

    public function generatesIdWhenHeaderIsAbsent(): void
    {
        $response = $this->middleware()->process($this->request(), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->generator->calls, 1);
    }

    #[DataProvider('unacceptableIdProvider')]
    public function generatesIdWhenIncomingIsUnacceptable(string $incoming): void
    {
        $response = $this->middleware()->process($this->request($incoming), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
    }

    public static function unacceptableIdProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'uuid v1' => ['aaaaaaaa-bbbb-1ccc-9ddd-eeeeeeeeeeee'];
        yield 'wrong variant' => ['aaaaaaaa-bbbb-4ccc-1ddd-eeeeeeeeeeee'];
        yield 'truncated' => ['aaaaaaaa-bbbb-4ccc-9ddd'];
        yield 'trailing garbage' => [self::INCOMING_ID . '-extra'];
        yield 'non-hex characters' => ['gggggggg-bbbb-4ccc-9ddd-eeeeeeeeeeee'];
        // CRLF never reaches the middleware — a conforming PSR-7 implementation
        // rejects such a header value outright. Smuggling within one header line
        // is what the pattern has to stop.
        yield 'space-smuggled content' => [self::INCOMING_ID . ' X-Evil: 1'];
        yield 'tab-smuggled content' => [self::INCOMING_ID . "\tX-Evil: 1"];
        yield 'over max length' => [str_repeat('a', 129)];
    }

    public function acceptsIncomingIdInAnyCase(): void
    {
        $upper = strtoupper(self::INCOMING_ID);

        $response = $this->middleware()->process($this->request($upper), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), $upper);
    }

    public function ignoresIncomingIdWhenAcceptIncomingIsOff(): void
    {
        $middleware = $this->middleware(acceptIncoming: false);

        $response = $middleware->process($this->request(self::INCOMING_ID), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->generator->calls, 1);
    }

    public function policyMayRejectAValidIncomingId(): void
    {
        $policy = new class implements IncomingCorrelationIdPolicy {
            public bool $called = false;
            public ?string $seenId = null;

            #[\Override]
            public function accepts(\Psr\Http\Message\ServerRequestInterface $request, string $id): bool
            {
                $this->called = true;
                $this->seenId = $id;

                return false;
            }
        };

        $response = $this->middleware(incomingPolicy: $policy)
            ->process($this->request(self::INCOMING_ID), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::true($policy->called);
        Assert::same($policy->seenId, self::INCOMING_ID);
    }

    public function policyDoesNotSeeInvalidIncomingId(): void
    {
        $policy = new class implements IncomingCorrelationIdPolicy {
            public bool $called = false;

            #[\Override]
            public function accepts(\Psr\Http\Message\ServerRequestInterface $request, string $id): bool
            {
                $this->called = true;

                return true;
            }
        };

        $this->middleware(incomingPolicy: $policy)
            ->process($this->request('invalid'), new FakeHandler());

        Assert::false($policy->called);
    }

    public function publishesIdAsRequestAttribute(): void
    {
        $handler = new FakeHandler();

        $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($handler->handledRequest?->getAttribute('correlationId'), self::INCOMING_ID);
    }

    public function usesConfiguredAttributeName(): void
    {
        $handler = new FakeHandler();

        $this->middleware(attributeName: 'requestId')->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($handler->handledRequest?->getAttribute('requestId'), self::INCOMING_ID);
    }

    public function usesConfiguredHeaderNameForBothDirections(): void
    {
        $middleware = $this->middleware(headerName: 'X-Correlation-ID');
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Correlation-ID', self::INCOMING_ID);

        $response = $middleware->process($request, new FakeHandler());

        Assert::same($response->getHeaderLine('X-Correlation-ID'), self::INCOMING_ID);
        Assert::same($response->getHeaderLine('X-Request-ID'), '');
    }

    public function holderCarriesIdWhileTheRequestIsInFlight(): void
    {
        $seen = null;
        $handler = new FakeHandler(function () use (&$seen): void {
            $seen = $this->holder->tryGet();
        });

        $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($seen, self::INCOMING_ID);
    }

    public function clearsHolderAfterTheRequest(): void
    {
        $this->middleware()->process($this->request(self::INCOMING_ID), new FakeHandler());

        Assert::null($this->holder->tryGet());
    }

    public function clearsHolderWhenTheHandlerThrows(): void
    {
        $handler = new FakeHandler(static function (): void {
            throw new RuntimeException('downstream failure');
        });

        try {
            $this->middleware()->process($this->request(self::INCOMING_ID), $handler);
        } catch (RuntimeException) {
            // The holder must not leak the ID into the next request of a worker.
        }

        Assert::null($this->holder->tryGet());
    }

    public function handlesSequentialRequestsOnASharedHolder(): void
    {
        $middleware = $this->middleware();

        $first = $middleware->process($this->request(self::INCOMING_ID), new FakeHandler());
        $second = $middleware->process($this->request(), new FakeHandler());

        Assert::same($first->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
        Assert::same($second->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
    }

    public function overwritesAnIdHeaderSetDownstream(): void
    {
        $handler = new FakeHandler();

        $response = $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::count($response->getHeader('X-Request-ID'), 1);
    }

    public function acceptsCustomIdFormatViaPatternAndGenerator(): void
    {
        $middleware = $this->middleware(validationPattern: '/^[0-9A-Z]{26}$/');
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $response = $middleware->process($this->request($ulid), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), $ulid);
    }

    public function rejectsIncomingIdLongerThanConfiguredMaxLength(): void
    {
        $this->generator = new FixedGenerator('generated');
        $middleware = $this->middleware(validationPattern: '/^[a-z-]+$/', maxLength: 9);

        $response = $middleware->process($this->request('incoming-id'), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), 'generated');
    }

    public function acceptsIncomingIdExactlyAtMaxLength(): void
    {
        $middleware = $this->middleware(maxLength: strlen(self::INCOMING_ID));

        $response = $middleware->process($this->request(self::INCOMING_ID), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
    }

    public function rejectsMaxLengthBelowOne(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Max length must be at least 1');

        $this->middleware(maxLength: 0);
    }

    public function acceptsMaxLengthOfOne(): void
    {
        $middleware = $this->middleware(validationPattern: '/^[a-z]$/', maxLength: 1);

        $response = $middleware->process($this->request('x'), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), 'x');
    }

    public function rejectsInvalidValidationPattern(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid validation pattern');

        $this->middleware(validationPattern: 'not-a-regex');
    }

    public function rejectsAbsentHeaderEvenUnderAPermissivePattern(): void
    {
        $middleware = $this->middleware(validationPattern: '/^.*$/');

        $response = $middleware->process($this->request(), new FakeHandler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
    }

    #[DataProvider('unacceptableGeneratedIdProvider')]
    public function rejectsUnacceptableGeneratedIdBeforeCallingTheHandler(string $generated): void
    {
        $this->generator = new FixedGenerator($generated);
        $handler = new FakeHandler();

        Expect::exception(UnexpectedValueException::class)
            ->withMessageContaining('does not satisfy validationPattern and maxLength');

        try {
            $this->middleware()->process($this->request(), $handler);
        } finally {
            Assert::null($handler->handledRequest);
            Assert::null($this->holder->tryGet());
        }
    }

    public static function unacceptableGeneratedIdProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong format' => ['not-a-uuid'];
        yield 'over max length' => [str_repeat('a', 129)];
    }

    #[Property(runs: 300)]
    public function responseAlwaysCarriesAnIdAcceptedByTheDefaultPattern(string $incoming): void
    {
        $holder = new CorrelationIdHolder();
        $middleware = new CorrelationIdMiddleware(generator: new Uuidv4Generator(), holder: $holder);

        $response = $middleware->process(
            (new ServerRequest('GET', '/'))->withHeader('X-Request-ID', $incoming),
            new FakeHandler(),
        );

        Assert::same(
            preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $response->getHeaderLine('X-Request-ID')),
            1,
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function responseAlwaysCarriesAnIdAcceptedByTheDefaultPatternGenerators(): array
    {
        // A hex-and-dash alphabet keeps the values header-legal while covering
        // both shapes that matter: near-miss IDs and oversized ones.
        return [
            'incoming' => Gen::stringFrom('0123456789abcdefABCDEF-', minLength: 0, maxLength: 200),
        ];
    }

    private function middleware(
        string $headerName = 'X-Request-ID',
        string $attributeName = 'correlationId',
        bool $acceptIncoming = true,
        string $validationPattern = CorrelationIdMiddleware::UUID_V4_PATTERN,
        int $maxLength = 128,
        IncomingCorrelationIdPolicy $incomingPolicy = new \Rasuvaeff\Yii3CorrelationId\AcceptAllIncomingCorrelationIdPolicy(),
    ): CorrelationIdMiddleware {
        return new CorrelationIdMiddleware(
            generator: $this->generator,
            holder: $this->holder,
            headerName: $headerName,
            attributeName: $attributeName,
            acceptIncoming: $acceptIncoming,
            validationPattern: $validationPattern,
            maxLength: $maxLength,
            incomingPolicy: $incomingPolicy,
        );
    }

    private function request(?string $incomingId = null): ServerRequest
    {
        $request = new ServerRequest('GET', '/');

        return $incomingId === null ? $request : $request->withHeader('X-Request-ID', $incomingId);
    }
}
