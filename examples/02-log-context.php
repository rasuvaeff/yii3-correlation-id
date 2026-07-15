<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;
use Yiisoft\Log\ContextProvider\CompositeContextProvider;
use Yiisoft\Log\ContextProvider\SystemContextProvider;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

require dirname(__DIR__) . '/vendor/autoload.php';

$holder = new CorrelationIdHolder();

// The correlation provider is composed with the logger's own system provider:
// yiisoft/log takes exactly one, and dropping SystemContextProvider would lose
// time/category/trace from every message.
$logger = new Logger(
    [new StreamTarget()],
    new CompositeContextProvider(
        new SystemContextProvider(),
        new CorrelationIdContextProvider($holder),
    ),
);

$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: $holder,
);

$handler = new class ($logger) implements RequestHandlerInterface {
    public function __construct(private readonly LoggerInterface $logger) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Nothing here knows about correlation IDs — the context provider reads
        // the holder on every message.
        $this->logger->info('order placed');
        $this->logger->warning('payment retried');

        return new Response(200);
    }
};

$middleware->process(new ServerRequest('GET', '/orders'), $handler);
$logger->flush(true);

// Outside a request no ID is set and logging still works — the context is
// simply missing the requestId key.
$logger->info('cron tick');
$logger->flush(true);
