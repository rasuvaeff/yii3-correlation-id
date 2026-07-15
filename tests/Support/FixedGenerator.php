<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests\Support;

use Rasuvaeff\Yii3CorrelationId\CorrelationIdGenerator;

final class FixedGenerator implements CorrelationIdGenerator
{
    public int $calls = 0;

    public function __construct(private readonly string $id) {}

    #[\Override]
    public function generate(): string
    {
        ++$this->calls;

        return $this->id;
    }
}
