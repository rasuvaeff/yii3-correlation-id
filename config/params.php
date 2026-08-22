<?php

declare(strict_types=1);

use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

return [
    'rasuvaeff/yii3-correlation-id' => [
        'headerName' => 'X-Request-ID',
        'attributeName' => 'correlationId',
        // Safe default: the caller does not choose this service's correlation
        // ID. Set it to true in the application params when this service sits
        // behind a trusted gateway and the ID should propagate across hops.
        'acceptIncoming' => false,
        'validationPattern' => CorrelationIdMiddleware::UUID_V4_PATTERN,
        'maxLength' => 128,
        'contextKey' => 'requestId',
    ],
];
