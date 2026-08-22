# rasuvaeff/yii3-correlation-id

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-correlation-id/v)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-correlation-id/downloads)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![Build](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-correlation-id/php)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

Request correlation ID for Yii3: PSR-15 middleware, request-scoped holder, and
yiisoft/log context provider. Every request gets an ID, every log line carries
it, and the client gets it back in the response header.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
- `psr/http-message` ^2.0, `psr/http-server-middleware` ^1.0
- `yiisoft/log` ^2.1 (2.1.0 introduced the `ContextProvider` API)

## Installation

```bash
composer require rasuvaeff/yii3-correlation-id
```

## Usage

Put `CorrelationIdMiddleware` first in the stack — everything downstream that
logs should already see the ID.

```php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: new CorrelationIdHolder(),
);
```

For each request the middleware:

1. Reads `X-Request-ID` and reuses the value if it is acceptable.
2. Generates a UUIDv4 otherwise.
3. Publishes the ID as the `correlationId` request attribute.
4. Publishes the ID in `CorrelationIdHolder`, replacing whatever was there.
5. Clears the holder in a `finally` block.
6. Sets `X-Request-ID` on the response.

Under `yiisoft/config` the bundled `config/di.php` wires all of this from
`params.php`, so the middleware only needs adding to your middleware stack.

For a Yii3 application, put the container ID first in the web runner's
middleware list (the exact filename depends on the application template):

```php
// config/web.php or config/common/middleware.php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Router\Middleware\Router;

return [
    CorrelationIdMiddleware::class,
    ErrorCatcher::class,
    Router::class,
];
```

Override package defaults in the application params layer, without copying the
vendor DI definitions:

```php
// config/common/params.php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

return [
    'rasuvaeff/yii3-correlation-id' => [
        'headerName' => 'X-Request-ID',
        'attributeName' => 'correlationId',
        'acceptIncoming' => false, // public ingress mints its own ID
        'validationPattern' => CorrelationIdMiddleware::UUID_V4_PATTERN,
        'maxLength' => 128,
        'contextKey' => 'requestId',
    ],
];
```

### Reading the ID

Anything holding the request reads the attribute; application services inject
the read-only `CorrelationIdProvider`:

```php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdProvider;

$id = $request->getAttribute('correlationId');

final readonly class OrderService
{
    public function __construct(
        private CorrelationIdProvider $correlationId,
    ) {}
}

$id = $correlationId->get();     // throws outside a correlation scope
$id = $correlationId->tryGet();  // null outside a correlation scope
```

`CorrelationIdHolder` must stay a **single shared instance** — the middleware
writes it and everything else reads it. The `yiisoft/di` container does this by
autowiring. The package aliases `CorrelationIdProvider` to that same instance;
application services should not depend on the holder's mutation methods.

The middleware **owns** the request scope: it overwrites whatever the holder
held and clears it in `finally`. A stray ID — left by worker bootstrap, by a
handler that called `exit()`, or by the middleware being registered twice — is
dropped on the next request instead of failing every request that worker will
ever handle again. `set()` keeps its set-once contract for application and queue
code, where a second write really is a mistake.

### Queue and console scopes

Queue consumers and console commands can establish an explicit scope without
manual cleanup. `runWith()` restores the previous ID in `finally`, including
for nested scopes and exceptions:

```php
$result = $holder->runWith(
    id: $message->correlationId,
    callback: fn () => $consumer->handle($message),
);
```

Scope IDs come from trusted application infrastructure and bypass the HTTP
validation settings. Do not pass arbitrary user input to `runWith()`.

### Outgoing HTTP requests

`CorrelationIdHeaderInjector` propagates the current ID into an outgoing PSR-7
request. It replaces a stale header rather than appending another value and is
a no-op outside a correlation scope:

```php
$request = $injector->inject($request);
$response = $httpClient->sendRequest($request);
```

The bundled DI config uses the same `headerName` as the server middleware. See
[examples/06-outgoing-request.php](examples/06-outgoing-request.php).

### Log context

`CorrelationIdContextProvider` puts `requestId` into the context of every
message logged through `Yiisoft\Log\Logger`. The logger takes exactly one
context provider, so compose yours with the logger's own `SystemContextProvider`
— dropping it would lose `time`, `category` and `trace` from every message:

```php
// config/common/di/logger.php
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Yiisoft\Log\ContextProvider\CompositeContextProvider;
use Yiisoft\Log\ContextProvider\SystemContextProvider;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

return [
    LoggerInterface::class => static fn (
        CorrelationIdContextProvider $correlationId,
    ): LoggerInterface => new Logger(
        [new StreamTarget()],
        new CompositeContextProvider(new SystemContextProvider(), $correlationId),
    ),
];
```

`ContextProviderInterface` belongs to `yiisoft/log`, so this package never binds
it — composing providers is the application's call.

Outside a request (console command, worker bootstrap) no ID is set, the context
provider returns an empty array, and logging keeps working.

### Configuration

`params.php`, under the `rasuvaeff/yii3-correlation-id` key:

| Param | Default | Meaning |
|---|---|---|
| `headerName` | `X-Request-ID` | Read from the request, written to the response |
| `attributeName` | `correlationId` | Request attribute carrying the ID |
| `acceptIncoming` | `true` | Reuse acceptable caller IDs; use `false` at a public trust boundary that mints IDs |
| `validationPattern` | UUIDv4 regex | Invalid incoming IDs are replaced; invalid generated IDs are rejected |
| `maxLength` | `128` | Longer incoming IDs are replaced; longer generated IDs are rejected |
| `contextKey` | `requestId` | Log context key |

A custom ID format needs a generator, matching pattern, and sufficient maximum
length. A generated value outside that contract throws `UnexpectedValueException`
before the request handler runs. See
[examples/04-custom-generator.php](examples/04-custom-generator.php).

Control characters (`\x00`-`\x1F`, `\x7F`) are rejected before
`validationPattern` runs, so a permissive custom pattern cannot let an ANSI
escape, a NUL byte, or a smuggled newline reach the holder, the logs, or an
outgoing header.

### Incoming trust policy

After format and length validation, an `IncomingCorrelationIdPolicy` may reject
an otherwise valid ID based on request context. Rejection generates a fresh ID.
Bind the policy in application DI:

```php
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;

final readonly class TrustedProxyPolicy implements IncomingCorrelationIdPolicy
{
    #[\Override]
    public function accepts(ServerRequestInterface $request, string $id): bool
    {
        return in_array(
            $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ['10.0.0.10', '10.0.0.11'],
            true,
        );
    }
}

return [
    IncomingCorrelationIdPolicy::class => TrustedProxyPolicy::class,
];
```

For manual construction, pass it as the named `incomingPolicy` argument.

`acceptIncoming: false` remains the hard off switch: it skips the policy and
always mints a new ID. See
[examples/07-trusted-proxy-policy.php](examples/07-trusted-proxy-policy.php).

### Public API

| Class | Description |
|---|---|
| `CorrelationIdMiddleware` | PSR-15 middleware: resolve, publish, echo back |
| `CorrelationIdProvider` | Read-only `get`/`tryGet` access for application services |
| `CorrelationIdHolder` | Mutable infrastructure holder: set-once `set()`, unconditional `override()`, and `runWith()` scopes |
| `CorrelationIdGenerator` | Interface for ID generation |
| `Uuidv4Generator` | Pure-PHP RFC 4122 v4 UUIDs from `random_bytes()` |
| `CorrelationIdContextProvider` | `yiisoft/log` context provider adding `requestId` |
| `CorrelationIdHeaderInjector` | Adds the current ID to outgoing PSR-7 requests |
| `IncomingCorrelationIdPolicy` | Request-aware trust decision for valid incoming IDs |
| `AcceptAllIncomingCorrelationIdPolicy` | Default policy used when none is supplied |
| `Exception\CorrelationIdNotSetException` | Thrown by `CorrelationIdHolder::get()` outside a request |

## When to use this instead of yii3-telemetry

| | `yii3-correlation-id` | `yii3-telemetry` |
|---|---|---|
| Scope | One service: correlate its own logs | Distributed tracing across services |
| Model | One ID per request | Spans, parent/child, sampling |
| Propagation | `X-Request-ID` header | W3C Trace Context, OTLP export |
| Cost | Middleware + holder, no exporter | Collector, exporter, sampling config |

Both can run together: place this middleware first and read `tryGet()` into a
span attribute. Do not expect this package to grow `traceparent` support — that
is what telemetry is for.

### Integration recipes

Keep optional package glue in the application. For `yii3-audit-log`, take the
ID from the provider rather than rereading an untrusted request header:

```php
use Rasuvaeff\Yii3AuditLog\AuditMetadata;

$metadata = new AuditMetadata(
    requestId: $correlationId->tryGet(),
    ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
    userAgent: $request->getHeaderLine('User-Agent'),
);
```

For `yii3-telemetry`, add it to the currently active span from code running
downstream of both middleware:

```php
$id = $correlationId->tryGet();
if ($id !== null) {
    $tracer->currentSpan()->setAttribute('request.id', $id);
}
```

## Security

| Risk | What the package does |
|---|---|
| Header injection | Control characters (`\x00`-`\x1F`, `\x7F`) are rejected unconditionally, before `validationPattern`, so a permissive custom pattern stays safe; the pattern then rejects anything that is not a well-formed ID, including content smuggled after a space |
| Oversized header | `maxLength` (default 128) rejects long values before the pattern runs |
| Client-spoofed ID | Set `acceptIncoming: false` at the public gateway; internal services accept that trusted ID and must not be directly reachable by clients |
| Log injection | Both incoming and generated IDs must pass the control-character guard, the validation pattern, and the length limit before reaching the holder or log context. `UUID_V4_PATTERN` is anchored with `\z`, not `$`, so reusing it in your own code does not accept a trailing newline |
| Info leak | A request ID carries no user data. UUIDv4 is unguessable but is **not** a secret — never use it for authorization |

**Browser access.** CORS does not expose custom response headers to JavaScript
by default. When a browser client must include the ID in a support report,
configure the application's CORS middleware to send:

```http
Access-Control-Expose-Headers: X-Request-ID
```

Use the configured custom header name instead when `headerName` is changed.

**Concurrency.** The holder is one shared instance cleared in a `finally` block,
which is correct for sequential request handling: PHP-FPM, and workers that take
one request at a time (RoadRunner). Under coroutine concurrency (Swoole), where
several requests share a worker's memory at once, a shared holder would leak IDs
between them — this package does not support that model.

## Examples

See [examples/](examples/) for runnable scripts.
Examples are expected to execute without fatal errors and stay aligned with the
documented public API.

| Script | Shows | Needs server? |
|---|---|---|
| [01-middleware-setup.php](examples/01-middleware-setup.php) | Middleware in a PSR-15 stack: generate / reuse / replace | no |
| [02-log-context.php](examples/02-log-context.php) | `yiisoft/log` + context provider: `requestId` on every line | no |
| [03-access-in-action.php](examples/03-access-in-action.php) | Reading the ID from the attribute and from the holder | no |
| [04-custom-generator.php](examples/04-custom-generator.php) | ULID-like generator with a matching validation pattern | no |
| [05-gateway-mode.php](examples/05-gateway-mode.php) | Public gateway replaces an untrusted ID, internal service preserves the gateway ID | no |
| [06-outgoing-request.php](examples/06-outgoing-request.php) | Queue scope and outgoing PSR-7 header propagation | no |
| [07-trusted-proxy-policy.php](examples/07-trusted-proxy-policy.php) | Accept a valid incoming ID only from a trusted gateway IP | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## License

[BSD-3-Clause](LICENSE.md)
