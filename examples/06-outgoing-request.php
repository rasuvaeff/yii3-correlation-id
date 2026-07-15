<?php

declare(strict_types=1);

use Nyholm\Psr7\Request;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHeaderInjector;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

require dirname(__DIR__) . '/vendor/autoload.php';

$holder = new CorrelationIdHolder();
$injector = new CorrelationIdHeaderInjector($holder);
$request = new Request('GET', 'https://inventory.internal/items/42');
$id = (new Uuidv4Generator())->generate();

$outgoing = $holder->runWith(
    id: $id,
    callback: static fn() => $injector->inject($request),
);

echo "outgoing ID: {$outgoing->getHeaderLine('X-Request-ID')}\n";
echo "outside scope: {$injector->inject($request)->getHeaderLine('X-Request-ID')}\n";
