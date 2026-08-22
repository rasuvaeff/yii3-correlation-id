<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

require dirname(__DIR__) . '/vendor/autoload.php';

$holder = new CorrelationIdHolder();
// `acceptIncoming: true` is an opt-in: since 2.0.0 the middleware ignores
// the caller's header by default, so that a service exposed to the public
// internet does not let its clients choose what its logs are keyed by. This
// example is about propagation, so it opts in.
$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: $holder,
    acceptIncoming: true,
);

$handler = new class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};

// A request without an ID: the middleware generates one.
$response = $middleware->process(new ServerRequest('GET', '/orders'), $handler);
echo "generated: {$response->getHeaderLine('X-Request-ID')}\n";

// A request with a valid ID: the middleware reuses it, so the client can
// correlate its own logs with the server's.
$clientId = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
$response = $middleware->process(
    (new ServerRequest('GET', '/orders'))->withHeader('X-Request-ID', $clientId),
    $handler,
);
echo "reused:    {$response->getHeaderLine('X-Request-ID')}\n";

// A request with a junk ID: rejected by the validation pattern, replaced.
$response = $middleware->process(
    (new ServerRequest('GET', '/orders'))->withHeader('X-Request-ID', 'not-a-uuid'),
    $handler,
);
echo "replaced:  {$response->getHeaderLine('X-Request-ID')}\n";

// The holder is cleared once the request is done.
var_dump($holder->tryGet());
