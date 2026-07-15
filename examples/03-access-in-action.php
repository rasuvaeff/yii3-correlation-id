<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Exception\CorrelationIdNotSetException;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

require dirname(__DIR__) . '/vendor/autoload.php';

$holder = new CorrelationIdHolder();
$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: $holder,
);

// Two ways to read the ID inside an action.
$handler = new class ($holder) implements RequestHandlerInterface {
    public function __construct(private readonly CorrelationIdHolder $holder) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 1. From the request attribute — for anything that already has the request.
        $fromAttribute = $request->getAttribute('correlationId');

        // 2. From the injected holder — for services buried deep in the call
        // stack, with no request to pass around.
        $fromHolder = $this->holder->get();

        echo "attribute: {$fromAttribute}\n";
        echo "holder:    {$fromHolder}\n";

        return new Response(200);
    }
};

$middleware->process(new ServerRequest('GET', '/orders'), $handler);

// Outside a request the holder is empty. get() fails loudly; tryGet() does not.
try {
    $holder->get();
} catch (CorrelationIdNotSetException $e) {
    echo "get() outside a request: {$e->getMessage()}\n";
}

var_dump($holder->tryGet());

// Console commands and queue workers use an exception-safe explicit scope.
$holder->runWith(
    id: 'cli-' . (new Uuidv4Generator())->generate(),
    callback: static function () use ($holder): void {
        echo "console scope: {$holder->get()}\n";
    },
);
var_dump($holder->tryGet());
