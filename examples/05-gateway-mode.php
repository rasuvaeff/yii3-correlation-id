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

$handler = new class implements RequestHandlerInterface {
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
};

$spoofed = 'aaaaaaaa-bbbb-4ccc-9ddd-eeeeeeeeeeee';
$request = (new ServerRequest('GET', '/orders'))->withHeader('X-Request-ID', $spoofed);

// Public gateway: replace the untrusted client value with an ID minted at the
// trust boundary. This is the default since 2.0.0; spelled out here because it
// is the whole point of the example.
$gateway = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: new CorrelationIdHolder(),
    acceptIncoming: false,
);
$gatewayId = $gateway->process($request, $handler)->getHeaderLine('X-Request-ID');
echo "client value: {$spoofed}\n";
echo "gateway ID:   {$gatewayId}\n";

// Internal service: direct client traffic is blocked by the deployment. It
// accepts the trusted gateway ID so logs remain correlated across services.
$internal = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: new CorrelationIdHolder(),
    // The deliberate opt-in: this service is unreachable from the internet, so
    // reusing the forwarded ID keeps the two services' logs correlated.
    acceptIncoming: true,
);
$forwarded = (new ServerRequest('GET', '/orders'))->withHeader('X-Request-ID', $gatewayId);
$internalId = $internal->process($forwarded, $handler)->getHeaderLine('X-Request-ID');
echo "internal ID:  {$internalId}\n";
