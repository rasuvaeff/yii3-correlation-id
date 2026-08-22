<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests\Support;

use LogicException;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdGenerator;

/**
 * Hands out a different ID on every call, so a test can tell whether two
 * middleware instances agreed on one ID or each minted its own.
 */
final class SequenceGenerator implements CorrelationIdGenerator
{
    public int $calls = 0;

    /** @var list<string> */
    private array $ids;

    public function __construct(string ...$ids)
    {
        $this->ids = array_values($ids);
    }

    #[\Override]
    public function generate(): string
    {
        $id = $this->ids[$this->calls] ?? throw new LogicException('Sequence generator ran out of IDs');
        ++$this->calls;

        return $id;
    }
}
