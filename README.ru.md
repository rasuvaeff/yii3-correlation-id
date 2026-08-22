# rasuvaeff/yii3-correlation-id

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-correlation-id/v)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-correlation-id/downloads)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![Build](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-correlation-id/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-correlation-id/php)](https://packagist.org/packages/rasuvaeff/yii3-correlation-id)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Correlation ID запроса для Yii3: PSR-15-middleware, request-scoped holder и
context-провайдер `yiisoft/log`. Каждый запрос получает ID, каждая строка
лога несёт его, а клиент получает его обратно в заголовке ответа.

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный
> API-справочник, которым можно поделиться с моделью.

## Требования

- PHP 8.3+
- `psr/http-message` ^2.0, `psr/http-server-middleware` ^1.0
- `yiisoft/log` ^2.1 (API `ContextProvider` появился в 2.1.0)

## Установка

```bash
composer require rasuvaeff/yii3-correlation-id
```

## Использование

Поставьте `CorrelationIdMiddleware` первым в стеке — всё, что ниже по потоку и
пишет логи, уже должно видеть ID.

```php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdHolder;
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;
use Rasuvaeff\Yii3CorrelationId\Uuidv4Generator;

$middleware = new CorrelationIdMiddleware(
    generator: new Uuidv4Generator(),
    holder: new CorrelationIdHolder(),
);
```

Для каждого запроса middleware:

1. Перенимает ID, который внешний экземпляр его же уже опубликовал в
   request-атрибуте `correlationId`, если такой есть.
2. Иначе читает `X-Request-ID` и переиспользует значение, если оно допустимо, —
   только при `acceptIncoming: true`, а это не значение по умолчанию.
3. Иначе генерирует UUIDv4.
4. Публикует ID как request-атрибут `correlationId`.
5. Публикует ID в `CorrelationIdHolder`, замещая то, что там было.
6. Очищает holder в блоке `finally` — кроме случая, когда ID был перенят на
   шаге 1: тогда очистка принадлежит внешнему экземпляру.
7. Устанавливает `X-Request-ID` в ответе.

Под `yiisoft/config` поставляемый `config/di.php` собирает всё это из
`params.php`, поэтому middleware нужно лишь добавить в стек.

Для Yii3-приложения поставьте container ID первым в списке middleware
web-раннера (точное имя файла зависит от шаблона приложения):

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

Перекройте дефолты пакета в слое параметров приложения, не копируя
vendor-DI-определения:

```php
// config/common/params.php
use Rasuvaeff\Yii3CorrelationId\CorrelationIdMiddleware;

return [
    'rasuvaeff/yii3-correlation-id' => [
        'headerName' => 'X-Request-ID',
        'attributeName' => 'correlationId',
        'acceptIncoming' => false, // значение по умолчанию: ID сервиса не выбирает вызывающий
        'validationPattern' => CorrelationIdMiddleware::UUID_V4_PATTERN,
        'maxLength' => 128,
        'contextKey' => 'requestId',
    ],
];
```

### Чтение ID

Любой, кто держит запрос, читает атрибут; сервисы приложения инжектят
read-only `CorrelationIdProvider`:

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

`CorrelationIdHolder` должен оставаться **единым shared-инстансом** — middleware
пишет в него, всё остальное читает. Контейнер `yiisoft/di` делает это через
autowiring. Пакет алиасит `CorrelationIdProvider` на тот же инстанс; сервисы
приложения не должны зависеть от мутабельных методов holder'а.

Middleware **владеет** областью запроса: он перезаписывает то, что лежало в
holder'е, и очищает его в `finally`. Осевший ID — оставленный bootstrap'ом
воркера или обработчиком, вызвавшим `exit()`, — сбрасывается на следующем
запросе, а не превращает каждый запрос этого воркера в ошибку навсегда. `set()`
сохраняет свой set-once-контракт для прикладного и очередного кода, где вторая
запись действительно является ошибкой.

### Двойная регистрация

Второй экземпляр ниже по стеку — middleware, добавленный дважды, или модуль со
своей копией — **перенимает** ID, который внешний экземпляр уже опубликовал в
request-атрибуте. Он не читает входящий заголовок заново, не чеканит
конкурирующий ID и не очищает holder на выходе, поскольку область принадлежит
внешнему экземпляру. Без этого один запрос нёс бы два ID: внутренний — в логах и
обработчике, внешний — в заголовке ответа.

Это же сохраняет смысл `acceptIncoming: false`: заголовок вызывающего остаётся в
запросе после того, как внешний экземпляр решил его игнорировать, и внутренний
экземпляр, настроенный с `acceptIncoming: true`, иначе прочитал бы его обратно.

Атрибут перенимается только после тех же проверок на управляющие символы,
`maxLength` и `validationPattern`, что и входящий заголовок, поэтому посторонний
код, пишущий этот атрибут (одноимённый параметр маршрута), не может решать,
каким будет correlation ID.

### Области очереди и консоли

Потребители очереди и консольные команды могут устанавливать явную область без
ручной очистки. `runWith()` восстанавливает предыдущий ID в `finally`, включая
вложенные области и исключения:

```php
$result = $holder->runWith(
    id: $message->correlationId,
    callback: fn () => $consumer->handle($message),
);
```

`set()`, `override()` и `runWith()` валидируют свой аргумент и бросают
`InvalidArgumentException` на ID, который пуст, длиннее 4096 байт или несёт
управляющий символ (`\x00`-`\x1F`, `\x7F`). Здесь это важнее всего:
`$message->correlationId` выше обычно возник как недоверенный HTTP-заголовок на
сервисе, который поставил задачу в очередь, а из holder'а он дословно попадает в
каждую строку лога и в каждый исходящий заголовок.

Валидация происходит до того, как состояние holder'а изменено, поэтому
отвергнутый вызов оставляет текущую область ровно такой, какой она была, а
callback не выполняется вовсе. Потолок в 4096 байт — предохранитель от разбухания
логов, а не проверка формата: он намеренно много выше дефолтного `maxLength`
middleware (128), чтобы кастомный формат, настроенный на middleware, никогда не
был отвергнут holder'ом следом.

Гарантия holder'а намеренно минимальна — это не HTTP-контракт валидации. Если ID
области обязан соответствовать конкретному формату, проверьте его против
`CorrelationIdMiddleware::UUID_V4_PATTERN` (или собственного паттерна) до вызова
`runWith()`.

### Исходящие HTTP-запросы

`CorrelationIdHeaderInjector` пробрасывает текущий ID в исходящий PSR-7-запрос.
Он заменяет устаревший заголовок, а не добавляет ещё одно значение, и является
no-op вне области корреляции:

```php
$request = $injector->inject($request);
$response = $httpClient->sendRequest($request);
```

Поставляемая DI-конфигурация использует тот же `headerName`, что и server-middleware.
См. [examples/06-outgoing-request.php](examples/06-outgoing-request.php).

### Контекст лога

`CorrelationIdContextProvider` кладёт `requestId` в контекст каждого сообщения,
протоколируемого через `Yiisoft\Log\Logger`. У логгера ровно один
context-провайдер, поэтому компонуйте свой с собственным `SystemContextProvider`
логгера — если его выкинуть, из каждого сообщения пропадут `time`, `category`
и `trace`:

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

`ContextProviderInterface` принадлежит `yiisoft/log`, поэтому этот пакет никогда
его не биндит — композиция провайдеров остаётся за приложением.

Вне запроса (консольная команда, bootstrap worker'а) ID не задан, context-провайдер
возвращает пустой массив, а логирование продолжает работать.

### Конфигурация

`params.php`, под ключом `rasuvaeff/yii3-correlation-id`:

| Параметр | По умолчанию | Значение |
|---|---|---|
| `headerName` | `X-Request-ID` | Читается из запроса, пишется в ответ |
| `attributeName` | `correlationId` | Request-атрибут, несущий ID; по нему же вложенный экземпляр опознаёт область внешнего |
| `acceptIncoming` | `false` | Игнорировать ID вызывающего и чеканить свой. Ставьте `true` только на сервисе, до которого не доходит прямой клиентский трафик, чтобы ID продолжал распространяться между сервисами |
| `validationPattern` | UUIDv4-regex | Невалидные входящие ID замещаются; невалидные сгенерированные ID отвергаются |
| `maxLength` | `128` | Более длинные входящие ID замещаются; более длинные сгенерированные ID отвергаются |
| `contextKey` | `requestId` | Ключ в контексте лога |

Для кастомного формата ID нужны генератор, соответствующий паттерн и достаточная
максимальная длина. Сгенерированное значение вне этого контракта выбрасывает
`UnexpectedValueException` до запуска request-handler'а. См.
[examples/04-custom-generator.php](examples/04-custom-generator.php).

Управляющие символы (`\x00`-`\x1F`, `\x7F`) отвергаются до применения
`validationPattern`, поэтому разрешительный пользовательский паттерн не пропустит
ANSI-escape, NUL-байт или спрятанный перевод строки в holder, логи или исходящий
заголовок.

### Доверие к ID вызывающего

`acceptIncoming` по умолчанию `false`. Middleware, которому не сказали, где он
стоит, считает себя публичной trust-границей и чеканит собственный ID: вызывающий
не может выбрать, по какому ключу пойдут логи этого сервиса, и не может заставить
два несвязанных запроса разделить один ID.

Ставьте `true` на сервисе, до которого не доходит прямой клиентский трафик, — там
проброшенный ID держит логи двух сервисов скоррелированными:

```php
// config/common/params.php — внутренний сервис за gateway
return [
    'rasuvaeff/yii3-correlation-id' => [
        'acceptIncoming' => true,
    ],
];
```

До 2.0.0 значением по умолчанию было `true`; см. [UPGRADE.md](UPGRADE.md).

### UUID-константа

`CorrelationIdMiddleware::UUID_V4_PATTERN` — значение `validationPattern` по
умолчанию, и её безопасно переиспользовать саму по себе: проверка correlation id
из сообщения очереди перед `runWith()`, проверка ID, прочитанного из БД:

```php
if (preg_match(CorrelationIdMiddleware::UUID_V4_PATTERN, $id) !== 1) {
    $id = $generator->generate();
}
```

Она заякорена через `\z`, который совпадает только в самом конце строки. До
1.0.1 включительно якорем был `$`, который PCRE сопоставляет и перед единственным
завершающим `\n`, — то есть старое значение при таком использовании возвращало
`1` на `"<uuid>\n"`. На поведении middleware это не сказывалось никогда: guard
управляющих символов отвергает завершающий перевод строки до применения любого
паттерна.

### Политика входящего доверия

После валидации формата и длины `IncomingCorrelationIdPolicy` может отвергнуть
иначе валидный ID на основании контекста запроса. Отказ генерирует свежий ID.
Привяжите политику в DI приложения:

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

Для ручной конструекции передайте её именованным аргументом `incomingPolicy`.

`acceptIncoming: false` — значение по умолчанию — остаётся жёстким выключателем:
он пропускает политику и всегда чеканит новый ID, поэтому политика вообще
вызывается только на middleware с `acceptIncoming: true`. См.
[examples/07-trusted-proxy-policy.php](examples/07-trusted-proxy-policy.php).

### Публичный API

| Класс | Описание |
|---|---|
| `CorrelationIdMiddleware` | PSR-15-middleware: resolve, publish, echo back |
| `CorrelationIdProvider` | Read-only доступ `get`/`tryGet` для сервисов приложения |
| `CorrelationIdHolder` | Мутабельный infrastructure-holder: set-once `set()`, безусловный `override()` и области `runWith()`. Каждая запись валидирует ID |
| `CorrelationIdGenerator` | Интерфейс генерации ID |
| `Uuidv4Generator` | Чистый PHP, UUID RFC 4122 v4 из `random_bytes()` |
| `CorrelationIdContextProvider` | Context-провайдер `yiisoft/log`, добавляющий `requestId` |
| `CorrelationIdHeaderInjector` | Добавляет текущий ID в исходящие PSR-7-запросы |
| `IncomingCorrelationIdPolicy` | Учитывающий запрос trust-вердикт для валидных входящих ID |
| `AcceptAllIncomingCorrelationIdPolicy` | Дефолтная политика, если никакая не задана |
| `Exception\CorrelationIdNotSetException` | Бросается `CorrelationIdHolder::get()` вне запроса |

## Когда использовать это вместо yii3-telemetry

| | `yii3-correlation-id` | `yii3-telemetry` |
|---|---|---|
| Область | Один сервис: коррелировать собственные логи | Распределённый трейсинг между сервисами |
| Модель | Один ID на запрос | Spans, parent/child, сэмплинг |
| Распространение | Заголовок `X-Request-ID` | W3C Trace Context, OTLP-экспорт |
| Стоимость | Middleware + holder, без exporter'а | Collector, exporter, конфигурация сэмплинга |

Оба могут работать вместе: поставьте это middleware первым и читайте `tryGet()`
в атрибут span. Не ждите, что пакет обрастёт поддержкой `traceparent` — для
этого и есть телеметрия.

### Рецепты интеграции

Держите опциональный package-glue в приложении. Для `yii3-audit-log` берите ID
из провайдера, а не перечитывайте недоверенный заголовок запроса:

```php
use Rasuvaeff\Yii3AuditLog\AuditMetadata;

$metadata = new AuditMetadata(
    requestId: $correlationId->tryGet(),
    ip: $request->getServerParams()['REMOTE_ADDR'] ?? null,
    userAgent: $request->getHeaderLine('User-Agent'),
);
```

Для `yii3-telemetry` добавьте его в текущий активный span из кода, исполняющегося
ниже обоих middleware:

```php
$id = $correlationId->tryGet();
if ($id !== null) {
    $tracer->currentSpan()->setAttribute('request.id', $id);
}
```

## Безопасность

| Риск | Что делает пакет |
|---|---|
| Header injection | Управляющие символы (`\x00`-`\x1F`, `\x7F`) отвергаются безусловно, до `validationPattern`, поэтому разрешительный пользовательский паттерн остаётся безопасным; далее паттерн отвергает всё, что не является well-formed ID, включая контент, спрятанный после пробела |
| Oversized header | `maxLength` (по умолчанию 128) отвергает длинные значения до запуска паттерна |
| Client-spoofed ID | `acceptIncoming` по умолчанию `false`, поэтому ID вызывающего игнорируется, пока сервис явно не согласится его принимать. Соглашайтесь только на внутренних сервисах, до которых не доходит прямой клиентский трафик |
| Log injection | И входящие, и сгенерированные ID обязаны пройти проверку на управляющие символы, validation-паттерн и лимит длины до того, как попасть в holder или контекст лога. Guard идёт первым, поэтому якорь `validationPattern` не может ослабить middleware. `CorrelationIdHolder` применяет собственный guard на управляющие символы и длину к `set()`, `override()` и `runWith()`, поэтому ID, входящий по очередному или консольному пути, тоже не пронесёт ANSI-escape или CR/LF в логи и исходящие заголовки |
| Info leak | Request ID не несёт пользовательских данных. UUIDv4 неугадываем, но **не** секрет — никогда не используйте его для авторизации |

**Браузерный доступ.** CORS по умолчанию не экспонирует кастомные
response-заголовки в JavaScript. Когда браузерный клиент должен включать ID в
обращение в поддержку, настройте CORS-middleware приложения на отправку:

```http
Access-Control-Expose-Headers: X-Request-ID
```

Используйте сконфигурированное кастомное имя заголовка, если `headerName`
изменено.

**Конкурентность.** Holder — один shared-инстанс, очищаемый в блоке `finally`,
что корректно для последовательной обработки запросов: PHP-FPM и worker'ы,
берущие по одному запросу за раз (RoadRunner). Под корутинной конкурентностью
(Swoole), где несколько запросов одновременно делят память worker'а, общий
holder утёк бы ID между ними — этот пакет такую модель не поддерживает.

## Примеры

См. [examples/](examples/) — исполняемые скрипты.
Ожидается, что примеры выполняются без fatal errors и остаются
согласованными с документированным публичным API.

| Скрипт | Что показывает | Нужен сервер? |
|---|---|---|
| [01-middleware-setup.php](examples/01-middleware-setup.php) | Middleware в PSR-15-стеке при `acceptIncoming: true`: генерация / переиспользование / замена | нет |
| [02-log-context.php](examples/02-log-context.php) | `yiisoft/log` + context-провайдер: `requestId` в каждой строке | нет |
| [03-access-in-action.php](examples/03-access-in-action.php) | Чтение ID из атрибута и из holder'а | нет |
| [04-custom-generator.php](examples/04-custom-generator.php) | ULID-подобный генератор с соответствующим validation-паттерном | нет |
| [05-gateway-mode.php](examples/05-gateway-mode.php) | Публичный gateway заменяет недоверенный ID, внутренний сервис сохраняет ID gateway'а | нет |
| [06-outgoing-request.php](examples/06-outgoing-request.php) | Область очереди и проброс исходящего PSR-7-заголовка | нет |
| [07-trusted-proxy-policy.php](examples/07-trusted-proxy-policy.php) | Принимать валидный входящий ID только с IP доверенного gateway'а (нужен `acceptIncoming: true`) | нет |

## Разработка

На хосте нет PHP/Composer — запускайте в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` поднимают `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет драйвера покрытия.

## Лицензия

[BSD-3-Clause](LICENSE.md)
