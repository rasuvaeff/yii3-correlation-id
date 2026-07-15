<?php

declare(strict_types=1);

use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

return [
    'rasuvaeff/yii3-correlation-id' => [
        'headerName' => 'X-Request-ID',
        'attributeName' => 'correlationId',
        'acceptIncoming' => true,
        'validationPattern' => CorrelationIdMiddleware::UUID_V4_PATTERN,
        'maxLength' => 128,
        'contextKey' => 'requestId',
    ],
];
