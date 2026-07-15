<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use Nyholm\Psr7\ServerRequest;
use Rasuvaeff\Yii3CorrelationId\AcceptAllIncomingCorrelationIdPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(AcceptAllIncomingCorrelationIdPolicy::class)]
final class AcceptAllIncomingCorrelationIdPolicyTest
{
    public function acceptsValidatedId(): void
    {
        $policy = new AcceptAllIncomingCorrelationIdPolicy();

        Assert::true($policy->accepts(new ServerRequest('GET', '/'), 'id'));
    }
}
