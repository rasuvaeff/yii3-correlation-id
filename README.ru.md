# rasuvaeff/yii3-correlation-id
[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-correlation-id/v)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-correlation-id/downloads)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![Build](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-correlation-id/php)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
Идентификатор корреляции запроса для Yii3: промежуточное программное обеспечение PSR-15, держатель области запроса и поставщик контекста
 yiisoft/log. Каждый запрос получает идентификатор, каждая строка журнала содержит его
, и клиент получает его обратно в заголовке ответа.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API, которой вы можете поделиться с моделью. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `psr/http-message` ^2.0, `psr/http-server-middleware` ^1.0
 - `yiisoft/log` ^2.1 (в версии 2.1.0 появился API `ContextProvider`)

## Установка
```bash
composer require rasuvaeff/yii3-correlation-id
```
## Использование
Сначала поместите CorrelationIdMiddleware в стек — все последующие элементы, которые регистрирует
, уже должны видеть этот идентификатор. @@ЛИНИЯ@@
```php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: new CorrelationIdHolder(),
);
```
Для каждого запроса промежуточное программное обеспечение:

 1. Считывает `X-Request-ID` и повторно использует значение, если оно приемлемо.
 2. В противном случае генерирует UUIDv4.
 3. Публикует идентификатор как атрибут запроса `correlationId`.
 4. Публикует идентификатор в CorrelationIdHolder.
 5. Очищает держатель в блоке «finally».
 6. Устанавливает `X-Request-ID` в ответе.

 В `yiisoft/config` входящий в комплект `config/di.php` связывает всё это с
 `params.php`, так что промежуточное программное обеспечение нужно только добавить в ваш стек промежуточного программного обеспечения.

 Для приложения Yii3 поместите идентификатор контейнера первым в списке промежуточного программного обеспечения
 веб-раннера (точное имя файла зависит от шаблона приложения):

```php
// config/web.php or config/common/middleware.php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Router\Middleware\Router;

return [
    CorrelationIdMiddleware::class,
    ErrorCatcher::class,
    Router::class,
];
```
Переопределить значения пакета по умолчанию на уровне параметров приложения без копирования определений DI поставщика
:

```php
// config/common/params.php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

return [
    'rasuvaeff/yii3-correlation-id' => [
        'headerName' => 'X-Request-ID',
        'attributeName' => 'correlationId',
        'acceptIncoming' => false, // public ingress mints its own ID
        'validationPattern' => CorrelationIdMiddleware::UUID_V4_PATTERN,
        'maxLength' => 128,
        'contextKey' => 'requestId',
    ],
];
```
### Чтение идентификатора
Все, что содержит запрос, считывает атрибут; службы приложений внедряют
 объект CorrelationIdProvider, доступный только для чтения:

```php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdProvider;

$id = $request->getAttribute('correlationId');

final readonly class OrderService
{
    public function __construct(
        private CorrelationIdProvider $correlationId,
    ) {}
}

$id = $correlationId->get();     // throws outside a correlation scope
$id = $correlationId->tryGet();  // null outside a correlation scope
```
`CorrelationIdHolder` должен оставаться **единственным общим экземпляром** — промежуточное программное обеспечение
 записывает его, а все остальное читает. Контейнер `yiisoft/di` делает это посредством автоматического подключения
. Пакет использует псевдоним CorrelationIdProvider для того же экземпляра; Сервисы приложений
 не должны зависеть от методов мутации владельца. @@ЛИНИЯ@@
### Области очереди и консоли
Потребители очереди и консольные команды могут устанавливать явную область без ручной очистки
. `runWith()` восстанавливает предыдущий идентификатор в `finally`, включая
 для вложенных областей и исключений:

```php
$result = $holder->runWith(
    id: $message->correlationId,
    callback: fn () => $consumer->handle($message),
);
```
Идентификаторы областей поступают из доверенной инфраструктуры приложений и обходят настройки проверки HTTP
. Не передавайте произвольный пользовательский ввод в `runWith()`. @@ЛИНИЯ@@
### Исходящие HTTP-запросы
`CorrelationIdHeaderInjector` передает текущий идентификатор в исходящий запрос PSR-7
. Он заменяет устаревший заголовок, а не добавляет другое значение, и
 не работает за пределами области корреляции:

```php
$request = $injector->inject($request);
$response = $httpClient->sendRequest($request);
```
В комплекте конфигурации DI используется то же имя заголовка, что и в промежуточном программном обеспечении сервера. См.
 [examples/06-outgoing-request.php](examples/06-outgoing-request.php). @@ЛИНИЯ@@
### Контекст журнала
`CorrelationIdContextProvider` помещает `requestId` в контекст каждого сообщения
, зарегистрированного через `Yiisoft\Log\Logger`. Регистратор использует ровно один поставщик контекста
, поэтому создайте свой собственный `SystemContextProvider`
 регистратора — при его удалении будут потеряны `время`, `категория` и `трассировка` из каждого сообщения:

```php
// config/common/di/logger.php
use Psr\Log\LoggerInterface;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdContextProvider;
use Yiisoft\Log\ContextProvider\CompositeContextProvider;
use Yiisoft\Log\ContextProvider\SystemContextProvider;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;

return [
    LoggerInterface::class => static fn (
        CorrelationIdContextProvider $correlationId,
    ): LoggerInterface => new Logger(
        [new StreamTarget()],
        new CompositeContextProvider(new SystemContextProvider(), $correlationId),
    ),
];
```
`ContextProviderInterface` принадлежит `yiisoft/log`, поэтому этот пакет никогда не связывает его с
 — создание провайдеров является вызовом приложения.

 Вне запроса (консольная команда, рабочая загрузка) идентификатор не установлен, поставщик контекста
 возвращает пустой массив, и ведение журнала продолжает работать. @@ЛИНИЯ@@
### Конфигурация
`params.php`, под ключом `rasuvaeff/yii3-correlation-id`:

 | Парам | По умолчанию | Значение |
 |---|---|---|
 | `имя_заголовка` | `X-Request-ID` | Прочитать из запроса, записанного в ответ |
 | `attributeName` | `идентификатор корреляции` | Атрибут запроса, содержащий идентификатор |
 | `acceptIncoming` | `правда` | Повторно используйте допустимые идентификаторы вызывающих абонентов; используйте `false` на границе публичного доверия, которая создает идентификаторы |
 | `validationPattern` | регулярное выражение UUIDv4 | Неверные входящие идентификаторы заменяются; недействительные сгенерированные идентификаторы отклоняются |
 | `maxLength` | `128` | Заменяются более длинные входящие идентификаторы; более длинные идентификаторы отклоняются |
 | `contextKey` | `requestId` | Контекстный ключ журнала |

 Для пользовательского формата идентификатора требуется генератор, соответствующий шаблон и достаточная максимальная длина
. Сгенерированное значение вне этого контракта выдает `UnexpectedValueException`
 перед запуском обработчика запроса. См.
 [examples/04-custom-generator.php](examples/04-custom-generator.php). @@ЛИНИЯ@@
### Политика входящего доверия
После проверки формата и длины IncomingCorrelationIdPolicy может отклонить
 другой действительный идентификатор на основе контекста запроса. Отказ генерирует новый идентификатор.
 Привяжите политику в приложении DI:

```php
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3CorrelationId\IncomingCorrelationIdPolicy;

final readonly class TrustedProxyPolicy implements IncomingCorrelationIdPolicy
{
    #[\Override]
    public function accepts(ServerRequestInterface $request, string $id): bool
    {
        return in_array(
            $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ['10.0.0.10', '10.0.0.11'],
            true,
        );
    }
}

return [
    IncomingCorrelationIdPolicy::class => TrustedProxyPolicy::class,
];
```
Для создания вручную передайте его как именованный аргумент incomingPolicy.

 `acceptIncoming: false` остается жестким выключателем: он пропускает политику, и
 всегда создает новый идентификатор. См.
 [examples/07-trusted-proxy-policy.php](examples/07-trusted-proxy-policy.php). @@ЛИНИЯ@@
### Публичный API
| Класс | Описание |
 |---|---|
 | `CorrelationIdMiddleware` | Промежуточное программное обеспечение PSR-15: разрешение, публикация, возврат |
 | `CorrelationIdProvider` | Доступ только для чтения `get`/`tryGet` для служб приложений |
 | `CorrelationIdHolder` | Держатель изменяемой инфраструктуры с однократными операциями и областями действия `runWith()` |
 | `ГенераторКорреляцииИд` | Интерфейс для генерации идентификаторов |
 | `Uuidv4Generator` | UUID Pure-PHP RFC 4122 v4 из `random_bytes()` |
 | `CorrelationIdContextProvider` | Поставщик контекста `yiisoft/log` добавляет `requestId` |
 | `CorrelationIdHeaderInjector` | Добавляет текущий идентификатор в исходящие запросы PSR-7 |
 | `IncomingCorrelationIdPolicy` | Решение о доверии с учетом запросов для действительных входящих идентификаторов |
 | `AcceptAllIncomingCorrelationIdPolicy` | Политика по умолчанию, используемая, если ничего не указано |
 | `Exception\CorrelationIdNotSetException` | Вызывается `CorrelationIdHolder::get()` вне запроса | @@ЛИНИЯ@@
## Когда использовать это вместо yii3-телеметрии
| | `yii3-идентификатор корреляции` | `yii3-телеметрия` |
 |---|---|---|
 | Область применения | Один сервис: коррелировать собственные журналы | Распределенная трассировка по сервисам |
 | Модель | Один идентификатор на запрос | Промежутки, родитель/потомок, выборка |
 | Распространение | Заголовок `X-Request-ID` | Контекст трассировки W3C, экспорт OTLP |
 | Стоимость | Промежуточное ПО + держатель, без экспортера | Сборщик, экспортер, конфигурация выборки |

 Оба могут работать вместе: сначала поместите это промежуточное программное обеспечение и прочитайте `tryGet()` в атрибуте диапазона
. Не ожидайте, что в этом пакете будет расширена поддержка трассировки — для
 нужна телеметрия. @@ЛИНИЯ@@
### Рецепты интеграции
Держите дополнительный упаковочный клей в приложении. Для `yii3-audit-log` возьмите идентификатор
 от провайдера, а не перечитывайте недоверенный заголовок запроса:

```php
use Rasuvaeff\Yii3AuditLog\AuditMetadata;

$metadata = new AuditMetadata(
    requestId: $correlationId->tryGet(),
    ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
    userAgent: $request->getHeaderLine('User-Agent'),
);
```
Для `yii3-telemetry` добавьте его в текущий активный диапазон из кода, выполняющего
 ниже обоих промежуточных программ:

```php
$id = $correlationId->tryGet();
if ($id !== null) {
    $tracer->currentSpan()->setAttribute('request.id', $id);
}
```
## Безопасность
| Риск | Что делает пакет |
 |---|---|
 | Внедрение заголовка | Соответствующая реализация PSR-7 уже отклоняет CRLF в значении заголовка; шаблон проверки дополнительно отклоняет все, что не является правильно сформированным идентификатором, включая контент, перенесенный после пробела или табуляции |
 | Негабаритный заголовок | `maxLength` (по умолчанию 128) отклоняет длинные значения до запуска шаблона |
 | Подделанный клиентом идентификатор | Установите `acceptIncoming: false` на общедоступном шлюзе; внутренние службы принимают этот доверенный идентификатор и не должны быть напрямую доступны клиентам |
 | Внедрение журналов | И входящие, и сгенерированные идентификаторы должны пройти шаблон проверки и ограничение длины, прежде чем достигнуть держателя или контекста журнала |
 | Утечка информации | Идентификатор запроса не содержит пользовательских данных. UUIDv4 невозможно угадать, но он **не** секрет — никогда не используйте его для авторизации |

 **Доступ через браузер.** CORS по умолчанию не предоставляет настраиваемые заголовки ответов для JavaScript
. Когда клиент браузера должен включить идентификатор в отчет поддержки,
 настройте промежуточное программное обеспечение CORS приложения для отправки:

```http
Access-Control-Expose-Headers: X-Request-ID
```
Вместо этого используйте настроенное имя пользовательского заголовка при изменении `headerName`.

 **Параллелизм.** Держателем является один общий экземпляр, очищенный в блоке `finally`,
, который подходит для последовательной обработки запросов: PHP-FPM, и рабочие процессы, которые принимают
 по одному запросу за раз (RoadRunner). При параллельном выполнении сопрограмм (Swoole), когда
 несколько запросов одновременно используют память работника, общий держатель будет передавать между ними идентификаторы
 — этот пакет не поддерживает такую ​​модель. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособных сценариев.
 Ожидается, что примеры будут выполняться без фатальных ошибок и соответствовать документированному
 общедоступному API.

 | Скрипт | Шоу | Нужен сервер? |
 |---|---|---|
 | [01-middleware-setup.php](examples/01-middleware-setup.php) | Промежуточное ПО в стеке PSR-15: генерирование/повторное использование/замена | нет |
 | [02-log-context.php](examples/02-log-context.php) | `yiisoft/log` + поставщик контекста: `requestId` в каждой строке | нет |
 | [03-access-in-action.php](examples/03-access-in-action.php) | Чтение идентификатора из атрибута и из держателя | нет |
 | [04-custom-generator.php](examples/04-custom-generator.php) | ULID-подобный генератор с соответствующим шаблоном проверки | нет |
 | [05-gateway-mode.php](examples/05-gateway-mode.php) | Публичный шлюз заменяет ненадежный идентификатор, внутренняя служба сохраняет идентификатор шлюза | нет |
 | [06-outgoing-request.php](examples/06-outgoing-request.php) | Объем очереди и распространение исходящего заголовка PSR-7 | нет |
 | [07-trusted-proxy-policy.php](examples/07-trusted-proxy-policy.php) | Принимайте действительный входящий идентификатор только с IP-адреса доверенного шлюза | нет | @@ЛИНИЯ@@
## Разработка
На хосте нет PHP/Composer — запустите в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```
Или с помощью Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```
`make test-coverage` и `makemutation` загружают `pcov` внутри контейнера
 `composer:2`, поскольку базовый образ не имеет драйвера покрытия. @@ЛИНИЯ@@
## Лицензия
[BSD-3-пункт](LICENSE.md)
