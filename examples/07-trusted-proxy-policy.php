<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

require dirname(__DIR__) . '/vendor/autoload.php';

final readonly class TrustedProxyPolicy implements IncomingCorrelationIdPolicy
{
    /**
     * @param list<string> $trustedIps
     */
    public function __construct(
        private array $trustedIps,
    ) {}

    #[\Override]
    public function accepts(ServerRequestInterface $request, string $id): bool
    {
        $remoteIp = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remoteIp) && in_array($remoteIp, $this->trustedIps, true);
    }
}

// `acceptIncoming: false` (the default since 2.0.0) skips the policy entirely
// and always mints a fresh ID — the policy only ever runs on the opt-in path.
$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: new CorrelationIdHolder(),
    acceptIncoming: true,
    incomingPolicy: new TrustedProxyPolicy(['10.0.0.10']),
);
$handler = new class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};
$gatewayId = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';

$trusted = (new ServerRequest('GET', '/', serverParams: ['REMOTE_ADDR' => '10.0.0.10']))
    ->withHeader('X-Request-ID', $gatewayId);
$untrusted = (new ServerRequest('GET', '/', serverParams: ['REMOTE_ADDR' => '203.0.113.10']))
    ->withHeader('X-Request-ID', $gatewayId);

echo "trusted:   {$middleware->process($trusted, $handler)->getHeaderLine('X-Request-ID')}\n";
echo "untrusted: {$middleware->process($untrusted, $handler)->getHeaderLine('X-Request-ID')}\n";
