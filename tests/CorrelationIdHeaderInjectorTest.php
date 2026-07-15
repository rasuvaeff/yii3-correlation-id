<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use Nyholm\Psr7\Request;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHeaderInjector;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(CorrelationIdHeaderInjector::class)]
final class CorrelationIdHeaderInjectorTest
{
    private CorrelationIdHolder $holder;
    private CorrelationIdHeaderInjector $fixture;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->holder = new CorrelationIdHolder();
        $this->fixture = new CorrelationIdHeaderInjector($this->holder);
    }

    public function injectsCurrentId(): void
    {
        $this->holder->set('id-1');

        $request = $this->fixture->inject(new Request('GET', 'https://service.test/orders'));

        Assert::same($request->getHeaderLine('X-Request-ID'), 'id-1');
    }

    public function overwritesStaleHeaderInsteadOfAppending(): void
    {
        $this->holder->set('id-1');
        $request = (new Request('GET', 'https://service.test/orders'))
            ->withAddedHeader('X-Request-ID', 'stale-1')
            ->withAddedHeader('X-Request-ID', 'stale-2');

        $result = $this->fixture->inject($request);

        Assert::same($result->getHeader('X-Request-ID'), ['id-1']);
    }

    public function returnsOriginalRequestOutsideScope(): void
    {
        $request = new Request('GET', 'https://service.test/orders');

        Assert::same($this->fixture->inject($request), $request);
    }

    public function usesConfiguredHeaderName(): void
    {
        $this->holder->set('id-1');
        $injector = new CorrelationIdHeaderInjector($this->holder, headerName: 'X-Correlation-ID');

        $request = $injector->inject(new Request('GET', 'https://service.test/orders'));

        Assert::same($request->getHeaderLine('X-Correlation-ID'), 'id-1');
        Assert::same($request->getHeaderLine('X-Request-ID'), '');
    }
}
