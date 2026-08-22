<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3CorrelationId\Tests;

use Rasuvaeff\Yii3CorrelationId\AcceptAllIncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdGenerator;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHeaderInjector;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdProvider;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Log\ContextProvider\ContextProviderInterface;

#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function generatorIsAliasedToTheUuidImplementation(): void
    {
        Assert::same($this->di()[CorrelationIdGenerator::class], Uuidv4Generator::class);
    }

    public function incomingPolicyIsAliasedToTheAcceptAllImplementation(): void
    {
        Assert::same(
            $this->di()[IncomingCorrelationIdPolicy::class],
            AcceptAllIncomingCorrelationIdPolicy::class,
        );
    }

    public function holderIsNotBoundExplicitly(): void
    {
        Assert::false(array_key_exists(CorrelationIdHolder::class, $this->di()));
    }

    public function containerSharesTheHolderAcrossAllConsumers(): void
    {
        $container = new Container(ContainerConfig::create()->withDefinitions($this->di()));
        $middleware = $container->get(CorrelationIdMiddleware::class);
        $provider = $container->get(CorrelationIdContextProvider::class);
        $injector = $container->get(CorrelationIdHeaderInjector::class);

        $middlewareHolder = $this->property($middleware, 'holder');
        $contextProvider = $this->property($provider, 'provider');
        $injectorProvider = $this->property($injector, 'provider');

        Assert::instanceOf($middlewareHolder, CorrelationIdHolder::class);
        Assert::same($middlewareHolder, $contextProvider);
        Assert::same($middlewareHolder, $injectorProvider);
        Assert::same($middlewareHolder, $container->get(CorrelationIdProvider::class));
        Assert::same($middlewareHolder, $container->get(CorrelationIdHolder::class));
    }

    public function foreignLogContextProviderKeyIsNotBound(): void
    {
        Assert::false(array_key_exists(ContextProviderInterface::class, $this->di()));
    }

    public function middlewareIsConfiguredFromParams(): void
    {
        $middleware = $this->container()->get(CorrelationIdMiddleware::class);

        Assert::same($this->property($middleware, 'headerName'), 'X-Request-ID');
        Assert::same($this->property($middleware, 'attributeName'), 'correlationId');
        Assert::false($this->property($middleware, 'acceptIncoming'));
        Assert::same($this->property($middleware, 'validationPattern'), CorrelationIdMiddleware::UUID_V4_PATTERN);
        Assert::same($this->property($middleware, 'maxLength'), 128);
        Assert::instanceOf($this->property($middleware, 'incomingPolicy'), AcceptAllIncomingCorrelationIdPolicy::class);
    }

    public function contextProviderIsConfiguredFromParams(): void
    {
        /** @var array{'__construct()': array<string, mixed>} $definition */
        $definition = $this->di()[CorrelationIdContextProvider::class];

        Assert::same($definition['__construct()'], ['contextKey' => 'requestId']);
    }

    public function headerInjectorIsConfiguredFromParams(): void
    {
        /** @var array{'__construct()': array<string, mixed>} $definition */
        $definition = $this->di()[CorrelationIdHeaderInjector::class];

        Assert::same($definition['__construct()'], ['headerName' => 'X-Request-ID']);
    }

    public function applicationParamsOverrideConfiguresTheContainer(): void
    {
        $params = array_replace_recursive($this->params(), [
            'rasuvaeff/yii3-correlation-id' => [
                'headerName' => 'X-Correlation-ID',
                // Opposite of the package default, so the assertion below fails
                // if the override stops reaching the container.
                'acceptIncoming' => true,
                'contextKey' => 'correlation_id',
            ],
        ]);
        $container = new Container(ContainerConfig::create()->withDefinitions($this->di($params)));

        Assert::same($this->property($container->get(CorrelationIdMiddleware::class), 'headerName'), 'X-Correlation-ID');
        Assert::true($this->property($container->get(CorrelationIdMiddleware::class), 'acceptIncoming'));
        Assert::same($this->property($container->get(CorrelationIdContextProvider::class), 'contextKey'), 'correlation_id');
        Assert::same($this->property($container->get(CorrelationIdHeaderInjector::class), 'headerName'), 'X-Correlation-ID');
    }

    public function paramsAreExposedUnderThePackageKey(): void
    {
        Assert::same(array_keys($this->params()), ['rasuvaeff/yii3-correlation-id']);
    }

    public function defaultParamsBuildAWorkingMiddleware(): void
    {
        $middleware = $this->container()->get(CorrelationIdMiddleware::class);

        Assert::instanceOf($middleware, CorrelationIdMiddleware::class);
    }

    private function container(?array $params = null): Container
    {
        return new Container(ContainerConfig::create()->withDefinitions($this->di($params)));
    }

    /**
     * @return array<string, mixed>
     */
    private function di(?array $params = null): array
    {
        $params ??= $this->params();

        return (static fn(array $params): array => require dirname(__DIR__) . '/config/di.php')($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(): array
    {
        /** @var array<string, mixed> $params */
        $params = require dirname(__DIR__) . '/config/params.php';

        return $params;
    }

    private function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
