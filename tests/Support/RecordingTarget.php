<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests\Support;

use Yiisoft\Log\Message;
use Yiisoft\Log\Target;

final class RecordingTarget extends Target
{
    /** @var list<Message> */
    public array $messages = [];

    #[\Override]
    protected function export(): void
    {
        $this->messages = array_merge($this->messages, $this->getMessages());
    }
}
