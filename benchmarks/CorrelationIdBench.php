<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Benchmarks;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;
use Testo\Bench;

final class CorrelationIdBench
{
    private const string VALID_ID = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';

    #[Bench(
        ['raw random hex' => [self::class, 'generateRandomHex']],
        calls: 100_000,
        iterations: 5,
    )]
    public static function generateUuid(): string
    {
        return (new Uuidv4Generator())->generate();
    }

    #[Bench(
        callables: [
            'without incoming header' => [self::class, 'processWithoutIncomingHeader'],
        ],
        calls: 50_000,
        iterations: 5,
    )]
    public static function processWithIncomingHeader(): ResponseInterface
    {
        return self::middleware()->process(
            (new ServerRequest('GET', '/'))->withHeader('X-Request-ID', self::VALID_ID),
            self::handler(),
        );
    }

    public static function processWithoutIncomingHeader(): ResponseInterface
    {
        return self::middleware()->process(new ServerRequest('GET', '/'), self::handler());
    }

    #[Bench(
        ['direct holder read' => [self::class, 'readHolderDirectly']],
        calls: 1_000_000,
        iterations: 5,
    )]
    public static function readLogContext(): array
    {
        $holder = new CorrelationIdHolder();
        $holder->set(self::VALID_ID);

        return (new CorrelationIdContextProvider($holder))->getContext();
    }

    private static function middleware(): CorrelationIdMiddleware
    {
        return new CorrelationIdMiddleware(
            generator: new Uuidv4Generator(),
            holder: new CorrelationIdHolder(),
        );
    }

    public static function generateRandomHex(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function readHolderDirectly(): ?string
    {
        $holder = new CorrelationIdHolder();
        $holder->set(self::VALID_ID);

        return $holder->tryGet();
    }

    private static function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
