<?php

declare(strict_types=1);

use Rasuvaeff\Yii3CorrelationId\AcceptAllIncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdGenerator;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHeaderInjector;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdProvider;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

/** @var array $params */

// CorrelationIdHolder is deliberately NOT bound: the container autowires it as
// a shared instance, which is what makes the writer (middleware) and the
// readers (read-only provider, log context, outgoing injector) see the same ID.
//
// Yiisoft\Log\ContextProvider\ContextProviderInterface is deliberately NOT bound
// either: it belongs to yiisoft/log, and the application composes its providers.
// See the README for the CompositeContextProvider recipe.

return [
    CorrelationIdGenerator::class => Uuidv4Generator::class,
    CorrelationIdProvider::class => static fn (CorrelationIdHolder $holder): CorrelationIdProvider => $holder,
    IncomingCorrelationIdPolicy::class => AcceptAllIncomingCorrelationIdPolicy::class,
    CorrelationIdMiddleware::class => static fn (
        CorrelationIdGenerator $generator,
        CorrelationIdHolder $holder,
        IncomingCorrelationIdPolicy $incomingPolicy,
    ): CorrelationIdMiddleware => new CorrelationIdMiddleware(
        generator: $generator,
        holder: $holder,
        headerName: $params['rasuvaeff/yii3-correlation-id']['headerName'],
        attributeName: $params['rasuvaeff/yii3-correlation-id']['attributeName'],
        acceptIncoming: $params['rasuvaeff/yii3-correlation-id']['acceptIncoming'],
        validationPattern: $params['rasuvaeff/yii3-correlation-id']['validationPattern'],
        maxLength: $params['rasuvaeff/yii3-correlation-id']['maxLength'],
        incomingPolicy: $incomingPolicy,
    ),
    CorrelationIdContextProvider::class => [
        '__construct()' => [
            'contextKey' => $params['rasuvaeff/yii3-correlation-id']['contextKey'],
        ],
    ],
    CorrelationIdHeaderInjector::class => [
        '__construct()' => [
            'headerName' => $params['rasuvaeff/yii3-correlation-id']['headerName'],
        ],
    ],
];
