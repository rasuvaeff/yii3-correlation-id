<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdGenerator;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\Tests\Support\NestingHandler;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;
use ReflectionClass;
use RuntimeException;
use Stringable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use UnexpectedValueException;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(CorrelationIdMiddleware::class)]
final class CorrelationIdMiddlewareTest
{
    private const string GENERATED_ID = '11111111-2222-4333-8444-555555555555';
    private const string SECOND_GENERATED_ID = '66666666-7777-4888-9999-aaaaaaaaaaaa';
    private const string INCOMING_ID = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
    private const string LOWERCASE_PATTERN = '/^[a-z]{1,40}$/';
    // The "ULID, opaque token" pattern the docblock of CorrelationIdGenerator
    // invites users to write. `/s` makes `.` match newlines too, so nothing but
    // the middleware's own guard stands between a control byte and the logs.
    private const string PERMISSIVE_PATTERN = '/^.{1,64}\z/s';

    private CorrelationIdHolder $holder;
    private CorrelationIdGenerator $generator;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->holder = new CorrelationIdHolder();
        $this->generator = $this->fixedGenerator(self::GENERATED_ID);
    }

    public function reusesAcceptableIncomingId(): void
    {
        $response = $this->middleware()->process($this->request(self::INCOMING_ID), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
        Understudy::unused($this->generator);
    }

    public function generatesIdWhenHeaderIsAbsent(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        verify(fn() => $this->generator->generate(), times: 1);
    }

    #[DataProvider('unacceptableIdProvider')]
    public function generatesIdWhenIncomingIsUnacceptable(string $incoming): void
    {
        $response = $this->middleware()->process($this->request($incoming), $this->handler());

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
        // PCRE `$` matches before a trailing `\n`; a smuggled `\n` must be
        // rejected instead of becoming the correlation ID for this request.
        yield 'trailing newline' => [self::INCOMING_ID . "\n"];
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

        $response = $this->middleware()->process($this->request($upper), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), $upper);
    }

    public function ignoresIncomingIdWhenAcceptIncomingIsOff(): void
    {
        $middleware = $this->middleware(acceptIncoming: false);

        $response = $middleware->process($this->request(self::INCOMING_ID), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        verify(fn() => $this->generator->generate(), times: 1);
    }

    /**
     * The safe default, constructed with no `acceptIncoming` argument at all
     * so the constructor's own default is what decides. A middleware that has
     * not been told it sits behind a trusted gateway must not let the caller
     * pick the ID its logs are keyed by.
     */
    public function ignoresTheIncomingIdByDefault(): void
    {
        $middleware = new CorrelationIdMiddleware(generator: $this->generator, holder: $this->holder);
        $handler = $this->handler();

        $response = $middleware->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($handler)->getAttribute('correlationId'), self::GENERATED_ID);
        verify(fn() => $this->generator->generate(), times: 1);
    }

    /**
     * The holder's own length ceiling sits far above any `maxLength` the
     * middleware would plausibly be configured with, so a documented custom
     * format (long opaque tokens under a permissive pattern) never has the
     * holder reject what the middleware just accepted.
     */
    public function aLongIdTheMiddlewareAcceptsReachesTheHolder(): void
    {
        $long = str_repeat('a', 512);
        $this->generator = $this->fixedGenerator($long);
        $seen = null;
        $handler = $this->observingHandler(function () use (&$seen): void {
            $seen = $this->holder->tryGet();
        });

        $response = $this->middleware(validationPattern: '/^a{1,1024}\z/', maxLength: 1024)
            ->process($this->request(), $handler);

        Assert::same($seen, $long);
        Assert::same($response->getHeaderLine('X-Request-ID'), $long);
    }

    public function policyMayRejectAValidIncomingId(): void
    {
        $policy = Understudy::for(IncomingCorrelationIdPolicy::class);
        when(fn() => $policy->accepts(Arg::any(), Arg::any()))->returns(false);

        $response = $this->middleware(incomingPolicy: $policy)
            ->process($this->request(self::INCOMING_ID), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        // The exact-argument verify replaces the old spy's `called` flag and
        // `seenId` recording in one claim: accepted exactly once, for this ID.
        verify(fn() => $policy->accepts(Arg::any(), self::INCOMING_ID), times: 1);
        Understudy::nothingElse($policy);
    }

    public function policyDoesNotSeeInvalidIncomingId(): void
    {
        $policy = Understudy::for(IncomingCorrelationIdPolicy::class);
        when(fn() => $policy->accepts(Arg::any(), Arg::any()))->returns(true);

        $this->middleware(incomingPolicy: $policy)
            ->process($this->request('invalid'), $this->handler());

        Understudy::unused($policy);
    }

    public function publishesIdAsRequestAttribute(): void
    {
        $handler = $this->handler();

        $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($this->handledRequest($handler)->getAttribute('correlationId'), self::INCOMING_ID);
    }

    public function usesConfiguredAttributeName(): void
    {
        $handler = $this->handler();

        $this->middleware(attributeName: 'requestId')->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($this->handledRequest($handler)->getAttribute('requestId'), self::INCOMING_ID);
    }

    public function usesConfiguredHeaderNameForBothDirections(): void
    {
        $middleware = $this->middleware(headerName: 'X-Correlation-ID');
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Correlation-ID', self::INCOMING_ID);

        $response = $middleware->process($request, $this->handler());

        Assert::same($response->getHeaderLine('X-Correlation-ID'), self::INCOMING_ID);
        Assert::same($response->getHeaderLine('X-Request-ID'), '');
    }

    public function holderCarriesIdWhileTheRequestIsInFlight(): void
    {
        $seen = null;
        $handler = $this->observingHandler(function () use (&$seen): void {
            $seen = $this->holder->tryGet();
        });

        $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($seen, self::INCOMING_ID);
    }

    public function clearsHolderAfterTheRequest(): void
    {
        $this->middleware()->process($this->request(self::INCOMING_ID), $this->handler());

        Assert::null($this->holder->tryGet());
    }

    public function clearsHolderWhenTheHandlerThrows(): void
    {
        $handler = $this->throwingHandler();

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

        $first = $middleware->process($this->request(self::INCOMING_ID), $this->handler());
        $second = $middleware->process($this->request(), $this->handler());

        Assert::same($first->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
        Assert::same($second->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
    }

    public function recoversFromAStrayIdLeftInTheHolder(): void
    {
        // Worker bootstrap (or a handler that called exit()) left an ID behind.
        // Before the fix `set()` threw here, and kept throwing for every
        // request this worker would ever handle again.
        $this->holder->set('left behind by worker bootstrap');

        $response = $this->middleware()->process($this->request(self::INCOMING_ID), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
        Assert::null($this->holder->tryGet());
    }

    public function aStrayIdNeverReachesTheHandler(): void
    {
        $this->holder->set('left behind by worker bootstrap');
        $seen = null;
        $handler = $this->observingHandler(function () use (&$seen): void {
            $seen = $this->holder->tryGet();
        });

        $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::same($seen, self::INCOMING_ID);
    }

    public function keepsServingRequestsAfterOutOfBandCodePoisonsTheHolder(): void
    {
        $middleware = $this->middleware();

        $middleware->process($this->request(self::INCOMING_ID), $this->handler());

        // Between two requests of the same worker: a scheduled task, a bootstrap
        // hook, anything that writes the holder outside the middleware's own
        // `finally`. The next request has to survive it.
        $this->holder->override('poisoned by out-of-band code');

        $second = $middleware->process($this->request(), $this->handler());

        Assert::same($second->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::null($this->holder->tryGet());
    }

    public function toleratesBeingRegisteredTwice(): void
    {
        $leaf = $this->handler();
        $outerHandler = new NestingHandler($this->middleware(), $leaf, $this->holder);

        $response = $this->middleware()->process($this->request(self::INCOMING_ID), $outerHandler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::INCOMING_ID);
        Assert::same($this->handledRequest($leaf)->getAttribute('correlationId'), self::INCOMING_ID);
        Assert::same($outerHandler->holderAfterInnerFinished, self::INCOMING_ID);
        Assert::null($this->holder->tryGet());
    }

    public function nestedRegistrationsAgreeOnOneIdWhenTheHeaderIsAbsent(): void
    {
        // Without the attribute being adopted, the inner instance mints its own
        // ID: the handler and the logs carry it while the outer instance still
        // writes its own to the response header. One request, two IDs.
        $generator = $this->sequencedGenerator(self::GENERATED_ID, self::SECOND_GENERATED_ID);
        $leaf = $this->handler();
        $outerHandler = new NestingHandler($this->middlewareWith($generator), $leaf, $this->holder);

        $response = $this->middlewareWith($generator)->process($this->request(), $outerHandler);

        verify(fn() => $generator->generate(), times: 1);
        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($leaf)->getAttribute('correlationId'), self::GENERATED_ID);
        Assert::same($outerHandler->holderAfterInnerFinished, self::GENERATED_ID);
        Assert::null($this->holder->tryGet());
    }

    public function nestingDoesNotLetAnInnerInstanceUndoATrustBoundary(): void
    {
        // The outer instance mints its own ID precisely so the caller's cannot
        // be trusted — but the caller's header is still on the request, and an
        // inner instance with the default `acceptIncoming: true` would happily
        // read it back and hand it to the handler and the logs.
        $generator = $this->sequencedGenerator(self::GENERATED_ID, self::SECOND_GENERATED_ID);
        $leaf = $this->handler();
        $outerHandler = new NestingHandler($this->middlewareWith($generator), $leaf, $this->holder);

        $response = $this->middlewareWith($generator, acceptIncoming: false)
            ->process($this->request(self::INCOMING_ID), $outerHandler);

        verify(fn() => $generator->generate(), times: 1);
        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($leaf)->getAttribute('correlationId'), self::GENERATED_ID);
        Assert::same($outerHandler->holderAfterInnerFinished, self::GENERATED_ID);
    }

    public function anInnerInstanceRestoresTheScopeAHandlerWroteOverOutOfBand(): void
    {
        // The inner instance does not own the scope, so it must not clear it on
        // the way out — the outer instance is still unwinding, and anything
        // decorating the response between the two layers reads the holder.
        $generator = $this->sequencedGenerator(self::GENERATED_ID, self::SECOND_GENERATED_ID);
        $leaf = $this->observingHandler(function (): void {
            $this->holder->override('written by the handler out of band');
        });
        $outerHandler = new NestingHandler($this->middlewareWith($generator), $leaf, $this->holder);

        $this->middlewareWith($generator)->process($this->request(), $outerHandler);

        Assert::same($outerHandler->holderAfterInnerFinished, self::GENERATED_ID);
        Assert::null($this->holder->tryGet());
    }

    public function anInnerInstanceKeepsTheScopeAliveWhileAnExceptionPropagates(): void
    {
        // The case the restore-instead-of-clear branch exists for: an error
        // handler between the two layers logs the failure and needs the ID.
        $generator = $this->sequencedGenerator(self::GENERATED_ID, self::SECOND_GENERATED_ID);
        $leaf = $this->throwingHandler();
        $outerHandler = new NestingHandler($this->middlewareWith($generator), $leaf, $this->holder);

        try {
            $this->middlewareWith($generator)->process($this->request(), $outerHandler);
        } catch (RuntimeException) {
            // The outer instance's `finally` has run by now; the interesting
            // window is one frame down, recorded by NestingHandler.
        }

        Assert::same($outerHandler->holderAfterInnerFinished, self::GENERATED_ID);
        Assert::null($this->holder->tryGet());
    }

    public function theOutermostInstanceStillSelfHealsUnderNesting(): void
    {
        // Self-healing and nesting-awareness have to coexist: the stray ID is
        // still dropped, because only an instance that found the attribute
        // treats itself as nested.
        $this->holder->set('left behind by worker bootstrap');
        $generator = $this->sequencedGenerator(self::GENERATED_ID, self::SECOND_GENERATED_ID);
        $leaf = $this->handler();
        $outerHandler = new NestingHandler($this->middlewareWith($generator), $leaf, $this->holder);

        $response = $this->middlewareWith($generator)->process($this->request(), $outerHandler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($leaf)->getAttribute('correlationId'), self::GENERATED_ID);
        Assert::null($this->holder->tryGet());
    }

    public function anAlreadyPublishedAttributeWinsOverTheIncomingHeader(): void
    {
        $handler = $this->handler();
        $request = $this->request(self::INCOMING_ID)
            ->withAttribute('correlationId', self::SECOND_GENERATED_ID);

        $response = $this->middleware()->process($request, $handler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::SECOND_GENERATED_ID);
        Assert::same($this->handledRequest($handler)->getAttribute('correlationId'), self::SECOND_GENERATED_ID);
        Understudy::unused($this->generator);
    }

    #[DataProvider('unadoptableAttributeProvider')]
    public function ignoresARequestAttributeThatIsNotAnAcceptableId(mixed $attribute): void
    {
        // Only this middleware is supposed to write that attribute, but nothing
        // enforces it — a route parameter or an unrelated middleware sharing
        // the name must not get to decide the correlation ID.
        $handler = $this->handler();
        $request = $this->request()->withAttribute('correlationId', $attribute);

        $response = $this->middleware()->process($request, $handler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($handler)->getAttribute('correlationId'), self::GENERATED_ID);
        verify(fn() => $this->generator->generate(), times: 1);
    }

    public static function unadoptableAttributeProvider(): iterable
    {
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'empty string' => [''];
        yield 'over max length' => [str_repeat('a', 129)];
        yield 'control character' => [self::INCOMING_ID . "\n"];
        yield 'not a string at all' => [42];
        yield 'array' => [[self::INCOMING_ID]];
        // A Stringable is not a string: adopting it would mean the ID reaching
        // the holder had never been through the validation contract.
        yield 'stringable object' => [new class implements Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
            }
        }];
    }

    public function theDefaultPatternRejectsATrailingNewlineOnItsOwn(): void
    {
        // The whole point of the `\z` anchor, seen from a consumer reusing the
        // constant outside the middleware — validating a queue message's
        // correlation id before `runWith()`, with none of the middleware's
        // guards in the way. Up to 1.0.1 this constant was `$`-anchored and
        // returned 1 for the first subject.
        Assert::same(preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, self::INCOMING_ID . "\n"), 0);
        Assert::same(preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, self::INCOMING_ID), 1);
    }

    /**
     * The load-bearing one: a `$`-anchored pattern accepts `"<uuid>\n"` on its
     * own, and the middleware still does not, because `isAcceptable()` runs
     * the control-character guard before any pattern. That is what makes the
     * anchor of a *user-supplied* `validationPattern` irrelevant inside
     * `process()` — the 1.0.1 default was exactly such a pattern.
     */
    #[DataProvider('bothAnchorsProvider')]
    public function rejectsATrailingNewlineUnderEitherAnchor(string $validationPattern): void
    {
        $handler = $this->handler();
        // nyholm's own header-value check is `$`-anchored, so a single
        // trailing `\n` really does reach the middleware.
        $request = $this->request(self::INCOMING_ID . "\n");

        $response = $this->middleware(validationPattern: $validationPattern)->process($request, $handler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($handler)->getAttribute('correlationId'), self::GENERATED_ID);
        verify(fn() => $this->generator->generate(), times: 1);
    }

    /**
     * The same, from the request attribute an outer instance of the middleware
     * would have published: that path validates too, and it validates the same
     * way under either anchor.
     */
    #[DataProvider('bothAnchorsProvider')]
    public function refusesToAdoptAnAttributeWithATrailingNewlineUnderEitherAnchor(string $validationPattern): void
    {
        $handler = $this->handler();
        $request = $this->request()->withAttribute('correlationId', self::INCOMING_ID . "\n");

        $response = $this->middleware(validationPattern: $validationPattern)->process($request, $handler);

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
        Assert::same($this->handledRequest($handler)->getAttribute('correlationId'), self::GENERATED_ID);
        verify(fn() => $this->generator->generate(), times: 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothAnchorsProvider(): iterable
    {
        yield '\z-anchored default' => [CorrelationIdMiddleware::UUID_V4_PATTERN];
        // Spelled out rather than derived from the constant: this is a
        // user-supplied loose pattern (and, historically, the 1.0.1 default),
        // and it must keep testing that shape after the constant moves on.
        yield 'user-supplied $-anchored' => ['/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'];
    }

    /**
     * The published constant and the constructor's published default parameter
     * value are two separate things backward-compatibility tooling compares.
     * They must not drift apart.
     */
    public function theConstantIsTheDefaultValidationPattern(): void
    {
        $parameters = (new ReflectionClass(CorrelationIdMiddleware::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        $defaults = [];

        foreach ($parameters as $parameter) {
            if ($parameter->getName() === 'validationPattern') {
                $defaults[] = $parameter->getDefaultValue();
            }
        }

        Assert::same($defaults, [CorrelationIdMiddleware::UUID_V4_PATTERN]);
    }

    #[DataProvider('controlCharacterProvider')]
    public function rejectsAGeneratedIdCarryingAControlCharacterUnderAPermissivePattern(string $generated): void
    {
        $this->generator = $this->fixedGenerator($generated);
        $handler = $this->handler();

        Expect::exception(UnexpectedValueException::class)
            ->withMessageContaining('does not satisfy validationPattern and maxLength');

        try {
            $this->middleware(validationPattern: self::PERMISSIVE_PATTERN)->process($this->request(), $handler);
        } finally {
            Understudy::unused($handler);
        }
    }

    public static function controlCharacterProvider(): iterable
    {
        yield 'nul byte' => ["opaque\x00token"];
        yield 'ansi osc escape' => ["opaque\x1B]0;pwned\x07token"];
        yield 'tab' => ["opaque\ttoken"];
        yield 'delete' => ["opaque\x7Ftoken"];
        yield 'carriage return' => ["opaque\rtoken"];
        yield 'line feed' => ["opaque\ntoken"];
        yield 'backspace' => ["opaque\x08token"];
        yield 'unit separator, top of the control range' => ["opaque\x1Ftoken"];
        yield 'start of heading, bottom of the control range' => ["\x01opaque"];
        yield 'trailing escape' => ["opaque\x1B"];
    }

    #[DataProvider('controlFreeIdProvider')]
    public function acceptsAControlFreeIdUnderAPermissivePattern(string $generated): void
    {
        $this->generator = $this->fixedGenerator($generated);

        $response = $this->middleware(validationPattern: self::PERMISSIVE_PATTERN)
            ->process($this->request(), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), $generated);
    }

    public static function controlFreeIdProvider(): iterable
    {
        yield 'opaque token' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        yield 'space, one above the control range' => ['opaque token'];
        yield 'tilde, one below delete' => ['opaque~token'];
        yield 'high byte, one above delete' => ["opaque\x80token"];
    }

    #[DataProvider('incomingControlCharacterProvider')]
    public function rejectsAnIncomingIdCarryingAControlCharacterUnderAPermissivePattern(string $incoming): void
    {
        $this->generator = $this->fixedGenerator('generated-id');

        $response = $this->middleware(validationPattern: self::PERMISSIVE_PATTERN)
            ->process($this->request($incoming), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), 'generated-id');
    }

    public static function incomingControlCharacterProvider(): iterable
    {
        // Only header values a conforming PSR-7 implementation lets through:
        // nyholm accepts TAB, and its own `$`-anchored value check accepts a
        // single trailing LF — exactly the smuggling this guard has to stop.
        yield 'tab' => ["opaque\ttoken"];
        yield 'trailing line feed' => ["opaque-token\n"];
    }

    public function overwritesAnIdHeaderSetDownstream(): void
    {
        $handler = $this->handler();

        $response = $this->middleware()->process($this->request(self::INCOMING_ID), $handler);

        Assert::count($response->getHeader('X-Request-ID'), 1);
    }

    public function acceptsCustomIdFormatViaPatternAndGenerator(): void
    {
        $middleware = $this->middleware(validationPattern: '/^[0-9A-Z]{26}$/');
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $response = $middleware->process($this->request($ulid), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), $ulid);
    }

    public function rejectsIncomingIdLongerThanConfiguredMaxLength(): void
    {
        $this->generator = $this->fixedGenerator('generated');
        $middleware = $this->middleware(validationPattern: '/^[a-z-]+$/', maxLength: 9);

        $response = $middleware->process($this->request('incoming-id'), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), 'generated');
    }

    public function acceptsIncomingIdExactlyAtMaxLength(): void
    {
        $middleware = $this->middleware(maxLength: strlen(self::INCOMING_ID));

        $response = $middleware->process($this->request(self::INCOMING_ID), $this->handler());

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

        $response = $middleware->process($this->request('x'), $this->handler());

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

        $response = $middleware->process($this->request(), $this->handler());

        Assert::same($response->getHeaderLine('X-Request-ID'), self::GENERATED_ID);
    }

    #[DataProvider('unacceptableGeneratedIdProvider')]
    public function rejectsUnacceptableGeneratedIdBeforeCallingTheHandler(string $generated): void
    {
        $this->generator = $this->fixedGenerator($generated);
        $handler = $this->handler();

        Expect::exception(UnexpectedValueException::class)
            ->withMessageContaining('does not satisfy validationPattern and maxLength');

        try {
            $this->middleware()->process($this->request(), $handler);
        } finally {
            Understudy::unused($handler);
            Assert::null($this->holder->tryGet());
        }
    }

    public static function unacceptableGeneratedIdProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong format' => ['not-a-uuid'];
        yield 'over max length' => [str_repeat('a', 129)];
    }

    #[Property(runs: 300, timeoutMs: 1000)]
    public function responseAlwaysCarriesAnIdAcceptedByTheDefaultPattern(string $incoming): void
    {
        $holder = new CorrelationIdHolder();
        // `acceptIncoming: true` explicitly — the property is about what the
        // middleware lets through from the wire, and the default is now to
        // ignore the wire entirely.
        $middleware = new CorrelationIdMiddleware(
            generator: new Uuidv4Generator(),
            holder: $holder,
            acceptIncoming: true,
        );

        $response = $middleware->process(
            (new ServerRequest('GET', '/'))->withHeader('X-Request-ID', $incoming),
            $this->handler(),
        );

        $id = $response->getHeaderLine('X-Request-ID');

        // Both branches have to be reached or the property degenerates into
        // "a freshly generated UUID is a UUID".
        Classify::cover($id === $incoming, 'incoming reused', 20.0);
        Classify::cover($id !== $incoming, 'freshly generated', 20.0);

        Assert::same(preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $id), 1);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function responseAlwaysCarriesAnIdAcceptedByTheDefaultPatternGenerators(): array
    {
        // Half the draws are IDs the default pattern accepts, so the reuse
        // branch is reached at all; the rest are header-legal near misses of
        // every length, which is where the pattern has to hold the line.
        return [
            'incoming' => Gen::frequency([
                [1, Gen::uuid()],
                [1, Gen::stringFrom('0123456789abcdefABCDEF-', minLength: 0, maxLength: 200)],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function responseAlwaysCarriesAnIdAcceptedByTheDefaultPatternExamples(): iterable
    {
        yield 'empty header value' => [''];
        yield 'canonical incoming id' => [self::INCOMING_ID];
        yield 'uppercase incoming id' => [strtoupper(self::INCOMING_ID)];
        yield 'uuid v1' => ['aaaaaaaa-bbbb-1ccc-9ddd-eeeeeeeeeeee'];
        yield 'wrong variant' => ['aaaaaaaa-bbbb-4ccc-1ddd-eeeeeeeeeeee'];
        yield 'trailing garbage' => [self::INCOMING_ID . '-extra'];
        yield 'over max length' => [str_repeat('a', 129)];
        yield 'trailing line feed' => [self::INCOMING_ID . "\n"];
        yield 'tab-smuggled content' => [self::INCOMING_ID . "\tX-Evil: 1"];
    }

    /**
     * A property body runs many times inside one test, so the double created
     * there is created fresh per run and the stub re-registered each time —
     * the adapter's reset covers the whole property, not a single run. Exact
     * `expect()` cardinalities would count every run, example and shrink at
     * once; a `when()` stub is the shape that stays correct here.
     */
    #[Property(runs: 300, timeoutMs: 1000)]
    public function reusesTheIncomingIdExactlyWhenTheDefaultPatternAcceptsIt(string $incoming): void
    {
        $generator = $this->fixedGenerator(self::GENERATED_ID);
        $middleware = new CorrelationIdMiddleware(
            generator: $generator,
            holder: new CorrelationIdHolder(),
            acceptIncoming: true,
        );

        $acceptable = $incoming !== ''
            && strlen($incoming) <= 128
            && preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $incoming) === 1;

        Classify::cover($acceptable, 'accepted', 20.0);
        Classify::cover(!$acceptable, 'rejected', 20.0);

        $response = $middleware->process(
            (new ServerRequest('GET', '/'))->withHeader('X-Request-ID', $incoming),
            $this->handler(),
        );

        Assert::same(
            $response->getHeaderLine('X-Request-ID'),
            $acceptable ? $incoming : self::GENERATED_ID,
        );
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function reusesTheIncomingIdExactlyWhenTheDefaultPatternAcceptsItGenerators(): array
    {
        return [
            'incoming' => Gen::frequency([
                [2, Gen::uuid()],
                [1, Gen::map(Gen::uuid(), static fn(string $id): string => strtoupper($id))],
                [2, Gen::stringFrom('0123456789abcdef-', minLength: 0, maxLength: 40)],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reusesTheIncomingIdExactlyWhenTheDefaultPatternAcceptsItExamples(): iterable
    {
        yield 'canonical incoming id' => [self::INCOMING_ID];
        yield 'uppercase incoming id' => [strtoupper(self::INCOMING_ID)];
        // nyholm's own header-value check is `$`-anchored, so this reaches the
        // middleware; both the pattern's `\z` and the control-character guard
        // have to turn it down.
        yield 'trailing line feed' => [self::INCOMING_ID . "\n"];
        yield 'tab-smuggled content' => [self::INCOMING_ID . "\tX-Evil: 1"];
        yield 'space-smuggled content' => [self::INCOMING_ID . ' X-Evil: 1'];
        yield 'empty header value' => [''];
    }

    #[Property(runs: 200, timeoutMs: 1000)]
    public function maxLengthRejectsAnIncomingIdTheCustomPatternWouldAccept(string $incoming, int $maxLength): void
    {
        $middleware = new CorrelationIdMiddleware(
            generator: $this->fixedGenerator('generated'),
            holder: new CorrelationIdHolder(),
            acceptIncoming: true,
            validationPattern: self::LOWERCASE_PATTERN,
            maxLength: $maxLength,
        );

        // Every generated value matches the pattern, so length is the only
        // thing left to decide the outcome.
        $withinLimit = strlen($incoming) <= $maxLength;

        Classify::cover($withinLimit, 'within maxLength', 15.0);
        Classify::cover(!$withinLimit, 'over maxLength', 15.0);

        $response = $middleware->process(
            (new ServerRequest('GET', '/'))->withHeader('X-Request-ID', $incoming),
            $this->handler(),
        );

        Assert::same($response->getHeaderLine('X-Request-ID'), $withinLimit ? $incoming : 'generated');
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function maxLengthRejectsAnIncomingIdTheCustomPatternWouldAcceptGenerators(): array
    {
        return [
            // Gen::regex() takes an undelimited pattern, so the very pattern
            // the middleware validates against is what generates the values —
            // no second spelling of the format to drift out of sync.
            'incoming' => Gen::regex(trim(self::LOWERCASE_PATTERN, '/')),
            // The floor keeps the fallback ID ('generated', 9 characters)
            // acceptable — a generator whose own ID exceeds maxLength throws
            // instead of answering the question this property asks.
            'maxLength' => Gen::intBetween(9, 20),
        ];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function maxLengthRejectsAnIncomingIdTheCustomPatternWouldAcceptExamples(): iterable
    {
        yield 'exactly at the limit' => ['abcdefghij', 10];
        yield 'one over the limit' => ['abcdefghijk', 10];
        yield 'single character under a wide limit' => ['a', 20];
    }

    /**
     * The `\z` anchor of `UUID_V4_PATTERN`, checked from a consumer's
     * position: nothing here goes through the middleware, so none of its
     * compensating guards can hide a slack anchor. The `$`-anchored 1.0.1
     * spelling of this constant fails this property — that is exactly the bug
     * 2.0.0 fixes.
     */
    #[Property(runs: 400, timeoutMs: 1000)]
    public function theDefaultPatternAcceptsExactlyCanonicalUuidV4Strings(string $candidate): void
    {
        $structural = $this->looksLikeUuidV4($candidate);

        Classify::cover($structural, 'canonical uuid v4', 20.0);
        Classify::cover(!$structural, 'not a uuid v4', 20.0);
        Classify::when(str_contains($candidate, "\n"), 'contains a line feed');

        Assert::same(preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $candidate) === 1, $structural);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theDefaultPatternAcceptsExactlyCanonicalUuidV4StringsGenerators(): array
    {
        return [
            'candidate' => Gen::frequency([
                [3, Gen::uuid()],
                [1, Gen::map(Gen::uuid(), static fn(string $id): string => strtoupper($id))],
                // The trap itself: a canonical UUID plus one trailing newline.
                [2, Gen::map(Gen::uuid(), static fn(string $id): string => $id . "\n")],
                // Header-legal and header-illegal near misses drawn from an
                // alphabet that mixes hex, the separator and control bytes — no
                // Assume, both verdicts arise naturally.
                [3, Gen::stringFrom("0123456789abcdefABCDEF-\n\r\t\x00\x1B", minLength: 0, maxLength: 40)],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theDefaultPatternAcceptsExactlyCanonicalUuidV4StringsExamples(): iterable
    {
        yield 'canonical' => [self::INCOMING_ID];
        yield 'uppercase' => [strtoupper(self::INCOMING_ID)];
        yield 'trailing line feed' => [self::INCOMING_ID . "\n"];
        yield 'trailing carriage return' => [self::INCOMING_ID . "\r"];
        yield 'leading line feed' => ["\n" . self::INCOMING_ID];
        yield 'two trailing line feeds' => [self::INCOMING_ID . "\n\n"];
        yield 'trailing nul byte' => [self::INCOMING_ID . "\x00"];
        yield 'trailing ansi escape' => [self::INCOMING_ID . "\x1B[31m"];
        yield 'empty' => [''];
        yield 'uuid v1' => ['aaaaaaaa-bbbb-1ccc-9ddd-eeeeeeeeeeee'];
        yield 'wrong variant' => ['aaaaaaaa-bbbb-4ccc-1ddd-eeeeeeeeeeee'];
        yield 'nul byte inside the last group' => ["aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeee\x00e"];
    }

    /**
     * A second, deliberately different spelling of "canonical UUID v4": no
     * regex, so it cannot share a bug with the constant under test.
     */
    private function looksLikeUuidV4(string $value): bool
    {
        if (strlen($value) !== 36) {
            return false;
        }

        $lower = strtolower($value);

        if ($lower[8] !== '-' || $lower[13] !== '-' || $lower[18] !== '-' || $lower[23] !== '-') {
            return false;
        }

        if ($lower[14] !== '4' || !in_array($lower[19], ['8', '9', 'a', 'b'], strict: true)) {
            return false;
        }

        foreach ([[0, 8], [9, 4], [15, 3], [20, 3], [24, 12]] as [$offset, $length]) {
            if (!ctype_xdigit(substr($lower, $offset, $length))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A generator double answering one fixed ID — the understudy replacement
     * for the hand-written `FixedGenerator` spy, with the call count read
     * back through `verify()` instead of a public counter.
     */
    private function fixedGenerator(string $id): CorrelationIdGenerator
    {
        $generator = Understudy::for(CorrelationIdGenerator::class);
        when(fn() => $generator->generate())->returns($id);

        return $generator;
    }

    /**
     * A generator double answering a different ID per call, so a test can
     * tell whether two middleware instances agreed on one ID or each minted
     * its own. `returns(a, b)` hands out one value per call and the last one
     * repeats, so a second mint surfaces both in the answer and in the
     * `verify(..., times: 1)` claim beside it.
     */
    private function sequencedGenerator(string ...$ids): CorrelationIdGenerator
    {
        $generator = Understudy::for(CorrelationIdGenerator::class);
        when(fn() => $generator->generate())->returns(...$ids);

        return $generator;
    }

    /**
     * A handler double answering 200 and recording every request it served;
     * `handledRequest()` reads the last one back from the call log.
     */
    private function handler(): RequestHandlerInterface
    {
        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))->returns(new Response(200));

        return $handler;
    }

    /**
     * @param Closure():(void|ResponseInterface) $spy runs while the request is
     *                                                  still in flight — the place to observe the holder
     */
    private function observingHandler(\Closure $spy): RequestHandlerInterface
    {
        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))
            ->answers(function () use ($spy): ResponseInterface {
                $spy();

                return new Response(200);
            });

        return $handler;
    }

    private function throwingHandler(): RequestHandlerInterface
    {
        $handler = Understudy::for(RequestHandlerInterface::class);
        when(fn() => $handler->handle(Arg::any()))->throws(new RuntimeException('downstream failure'));

        return $handler;
    }

    /**
     * The request the handler served last, read back from the double's call
     * log — the replacement for the old fake's `handledRequest` property.
     */
    private function handledRequest(RequestHandlerInterface $handler): ServerRequestInterface
    {
        $calls = Understudy::calls(fn() => $handler->handle(Arg::any()));

        return $calls[count($calls) - 1]->args[0];
    }

    /**
     * `$acceptIncoming` defaults to true here and false in the middleware, on
     * purpose: most tests below are about what happens to an incoming header,
     * and they would all become vacuous under the production default. The
     * production default has its own dedicated test
     * (`ignoresTheIncomingIdByDefault`), which constructs the middleware
     * directly and passes no argument.
     */
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

    /**
     * The nesting tests need two instances drawing from one generator that
     * answers differently every call — `$this->generator` is fixed by design.
     */
    private function middlewareWith(
        CorrelationIdGenerator $generator,
        bool $acceptIncoming = true,
    ): CorrelationIdMiddleware {
        return new CorrelationIdMiddleware(
            generator: $generator,
            holder: $this->holder,
            acceptIncoming: $acceptIncoming,
        );
    }

    private function request(?string $incomingId = null): ServerRequest
    {
        $request = new ServerRequest('GET', '/');

        return $incomingId === null ? $request : $request->withHeader('X-Request-ID', $incomingId);
    }
}
