<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdGenerator;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

// A non-UUID format: Crockford base32, time-ordered like a ULID.
final readonly class UlidLikeGenerator implements CorrelationIdGenerator
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    #[\Override]
    public function generate(): string
    {
        $id = '';

        for ($i = 0; $i < 26; ++$i) {
            $id .= self::ALPHABET[random_int(0, 31)];
        }

        return $id;
    }
}

// The generator and the validation pattern must agree: the pattern decides
// which incoming IDs are reusable, and it has to accept what the generator
// emits. Leave them out of sync and every request regenerates.
//
// Anchor with `\z`, not `$`: PCRE `$` also matches before a single trailing
// `\n`. Even if you forget, control characters (\x00-\x1F, \x7F) are rejected
// by the middleware before your pattern runs, so a deliberately permissive
// pattern still cannot leak an escape sequence into your logs.
$middleware = new CorrelationIdMiddleware(
    generator: new UlidLikeGenerator(),
    holder: new CorrelationIdHolder(),
    // Opt in to reusing the caller's ID; the default since 2.0.0 is to ignore
    // it. Needed here to show the custom pattern accepting and rejecting.
    acceptIncoming: true,
    validationPattern: '/^[0-9A-HJKMNP-TV-Z]{26}\z/',
    maxLength: 26,
);

$handler = new class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};

$response = $middleware->process(new ServerRequest('GET', '/orders'), $handler);
echo "generated: {$response->getHeaderLine('X-Request-ID')}\n";

$response = $middleware->process(
    (new ServerRequest('GET', '/orders'))->withHeader('X-Request-ID', '01ARZ3NDEKTSV4RRFFQ69G5FAV'),
    $handler,
);
echo "reused:    {$response->getHeaderLine('X-Request-ID')}\n";

// A UUID is no longer acceptable under this pattern — it gets replaced.
$response = $middleware->process(
    (new ServerRequest('GET', '/orders'))->withHeader('X-Request-ID', 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee'),
    $handler,
);
echo "replaced:  {$response->getHeaderLine('X-Request-ID')}\n";
