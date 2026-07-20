# rasuvaeff/yii3-telemetry

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry.svg)](https://github.com/rasuvaeff/yii3-telemetry/blob/master/LICENSE.md)
[English version](README.md)

Независимое от вендора ядро трассировки для Yii3. Один эргономичный вызов —
`trace(name, callback)` — открывает span, выполняет ваш код и закрывает span,
вместо многословного span-builder из OpenTelemetry. Экспортёр — сменный backend.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> который можно передать модели как контекст.

## Требования

- PHP 8.3+ (64-бит — наносекунды эпохи превышают `PHP_INT_MAX` на 32-битных сборках)
- `open-telemetry/api` ^1.5 (тонкая зависимость: интерфейсы + `NoopTracer`, без SDK)
- Интерфейсы PSR-20 (часы), PSR-3 (логирование), PSR-7 (HTTP-сообщения)

## Установка

```bash
composer require rasuvaeff/yii3-telemetry
```

Для реального экспорта span-ов подключите backend (Sprint 2): `rasuvaeff/yii3-telemetry-otel`.
Без него привяжите no-op провайдер (см. [Подключение](#подключение-yiisoftconfig)).

## Использование

### Трассировка блока работы

```php
use Rasuvaeff\Yii3Telemetry\SpanInterface;
use Rasuvaeff\Yii3Telemetry\TraceKind;
use Rasuvaeff\Yii3Telemetry\Tracer;

/** @var Tracer $tracer (injected) */
$order = $tracer->trace(
    name: 'checkout.process',
    callback: static function (SpanInterface $span) use ($cart): Order {
        $order = $cart->checkout();
        $span->setAttribute('order.id', $order->getId());

        return $order;
    },
    attributes: ['user.id' => $userId],
    scoped: true,
    traceKind: TraceKind::Internal,
);
```

Коллбэк получает активный `SpanInterface`, а его возвращаемое значение
становится возвращаемым значением `trace()`.

### Контракт `trace()` (зафиксирован в 1.0.0)

| Ситуация | Поведение |
|---|---|
| коллбэк возвращает значение | span завершается с текущим статусом; значение возвращается |
| коллбэк бросает исключение | вызывается `recordException()`, статус `Error`, span завершается, исходное исключение **пробрасывается дальше** |
| `scoped: true` (по умолчанию) | span является `currentSpan()` во время коллбэка; предыдущий span восстанавливается после |
| вложенный `trace()` | дочерний элемент наследует `traceId` родителя, получает собственный `spanId` |
| span отброшен / трассировка отключена | коллбэк всё равно выполняется; `currentSpan()` возвращает **non-recording** span, никогда `null` |
| `startNanos: <int>` | сдвигает начало span в прошлое (наносекунды unix-эпохи) для работы, которая логически началась раньше — timestamp получения воркером, время постановки в очередь; `null` (по умолчанию) = сейчас |

`end()` идемпотентен.

### Span

`SpanInterface` — это то, что получает коллбэк:

| Метод | Назначение |
|---|---|
| `setAttribute(string $key, bool\|int\|float\|string\|array\|null $value)` | добавить ключ/значение |
| `updateName(string $name)` | переименовать span |
| `setStatus(SpanStatusCode $code, ?string $description = null)` | задать статус |
| `addEvent(string $name, array $attributes = [])` | записать аннотацию с timestamp (OTel span event: `retry`, `cache.miss`, …) |
| `recordException(\Throwable $e)` | записать исключение |
| `end()` | завершить (идемпотентно) |
| `isRecording()` | `false` для non-recording span-ов |
| `getTraceContext()` | `TraceContext` данного span-а |

Конкретный `Span` (записываемый `LogTracer`) дополнительно предоставляет геттеры:
`getName()`, `getKind()`, `getStatus(): SpanStatus` (value object, объединяющий
`SpanStatusCode` с необязательным описанием), `getAttributes()`,
`getEvents(): list<SpanEvent>`, `getRecordedExceptions()`, `getDurationNanos()`,
`hasEnded()`.

### Трейсеры

| Класс | Назначение |
|---|---|
| `Tracer` | DI-фасад; делегирует активному `TracerInterface` из провайдера |
| `NullTracer` / `NullTracerProvider` | no-op; выполняет коллбэк с non-recording span |
| `LogTracer` | dev-трейсер; записывает реальные span-ы и логирует каждый завершённый span через PSR-3 |

```php
use Rasuvaeff\Yii3Telemetry\LogTracer;

$tracer = new LogTracer($psrLogger); // logs every finished span, no backend
```

### Распространение контекста (W3C Trace Context)

```php
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;

$propagator = new TraceContextPropagator();

// Incoming server request → context.
$context = $propagator->extract($serverRequest);

// Context → outgoing client request (adds the `traceparent` header).
$request = $propagator->inject($context, $clientRequest);
```

`extract` читает `ServerRequestInterface`; `inject` записывает в исходящий
`RequestInterface`. Отсутствующий или некорректный заголовок даёт
`TraceContext::invalid()`.

Для не-HTTP транспортов используйте не зависящую от carrier пару — обычную карту
заголовков, которую можно вложить в любой конверт (сообщение очереди, таблицу
заголовков AMQP, метаданные gRPC):

```php
// Producer: attach the current context to the message envelope.
$envelope['headers'] = $propagator->toHeaders($tracer->getContext());

// Consumer: restore it and open a Consumer span.
$context = $propagator->fromHeaders($message['headers'] ?? []);
```

`fromHeaders` сопоставляет имена case-insensitively; некорректный контекст даёт
пустую карту из `toHeaders`, поэтому round trip всегда безопасен.

> **Roadmap по инструментированию очередей.** Готовый middleware для `yiisoft/queue`
> (Producer inject + Consumer span) отложен до выхода стабильного релиза
> `yiisoft/queue` — описанный выше carrier API — поддерживаемый способ
> распространения трассировки через любую очередь сегодня.

### Часы

`ClockInterface` расширяет PSR-20 монотонным чтением — два измерения, которые
нельзя смешивать:

- `now(): \DateTimeImmutable` — wall clock (timestamp начала span-а);
- `monotonicNanos(): int` — `hrtime`, для измерения длительностей.

`SystemClock` — реализация по умолчанию (и валидные PSR-20 часы).

## Подключение (`yiisoft/config`)

Ядро в `config/di.php` биндит **только** фасад (`Tracer`, `TracerInterface`).
Оно никогда не биндит `TracerProviderInterface` — этот сменный ключ принадлежит
ровно одному источнику. Без установленного backend-а привяжите no-op провайдер в
вашем приложении:

```php
// config/common/di.php
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;

return [
    TracerProviderInterface::class => NullTracerProvider::class,
];
```

Установка `yii3-telemetry-otel` даёт реальный биндинг вместо этого — биндинг в
двух vendor-пакетах является преднамеренной ошибкой `yiisoft/config` `Duplicate key`.

## Инструментирование

Backend-агностичное инструментирование, записывающее span-ы через фасад. Подключайте
его **на стороне приложения** — никогда безусловно в пакете `di.php`, иначе контейнер
упадёт, когда подсистема не установлена.

| Класс | Оборачивает / слушает | Span-ы |
|---|---|---|
| `HttpClientSpanDecorator` | PSR-18 клиент | `HTTP <method>` (+ инжект `traceparent`) |
| `TracingCacheDecorator` | PSR-16 кеш | `cache.<op>` |
| `DbQueryProfiler` | профайлер `yiisoft/db` | `db.query` (только параметризованный SQL) |
| `ViewRenderSpanListener` | PSR-14 события `yiisoft/view` | `view.render` |
| `TraceContextLogger` | PSR-3 логгер | добавляет `trace_id`/`span_id` в контекст лога |
| `TraceIdResponseHeaderMiddleware` | PSR-15 ответ | заголовок ответа `X-Trace-Id` (opt-in) |

```php
// HTTP client (PSR-18) — inner client is wrapped
$client = new HttpClientSpanDecorator($innerClient, $tracer);

// Cache (PSR-16)
$cache = new TracingCacheDecorator($innerCache, $tracer);

// DB (yiisoft/db) — pass the semconv db.system for your driver (default 'sql')
$connection->setProfiler(new DbQueryProfiler($tracer, dbSystem: 'postgresql'));

// View (yiisoft/view) — register in config/events.php
BeforeRender::class => [[ViewRenderSpanListener::class, 'beforeRender']],
AfterRender::class  => [[ViewRenderSpanListener::class, 'afterRender']],
```

`DbQueryProfiler` и `ViewRenderSpanListener` обрамляют разделённые begin/end хуки
подсистемы через `Tracer::startSpan()` (manual span, который завершает вызывающий).
`yiisoft/db` и `yiisoft/view` опциональны (`suggest`); их символы объявлены в
`composer-require-checker.json`.

### Корреляция логов и раскрытие trace id

```php
// Wrap the application logger — every record inside an active trace gets
// trace_id / span_id in its context (existing keys are never overwritten):
$logger = new TraceContextLogger($innerLogger, $tracer);

// Opt-in: return the trace id to the client for support tickets. Place it
// AFTER the tracing middleware (inside the root span):
$middleware = new TraceIdResponseHeaderMiddleware($tracer);              // X-Trace-Id
$middleware = new TraceIdResponseHeaderMiddleware($tracer, 'Trace-Ref'); // custom name
```

Без активного валидного trace-контекста оба прозрачны: лог-запись и ответ
проходят без изменений.

## Безопасность

- **Безопасность SQL**: `DbQueryProfiler` помещает в `db.statement` только
  **параметризованный** SQL — значения параметров никогда не прикрепляются к span-у.
  Опция отладки для значений параметров и порог медленных запросов намеренно не
  реализованы; если они появятся позже, то будут отключены по умолчанию.
- `TraceContext` валидирует id (hex32 / hex16) и флаги (0..255) в конструкторе;
  некорректные заголовки распространения отбрасываются, им не доверяют.
- `trace()` никогда не проглатывает исключения — сбои остаются видимыми.

## Примеры

Исполняемые, не зависящие от сервера скрипты лежат в [`examples/`](examples/):
`01_basic_trace.php`, `02_nested_trace.php`, `03_propagation.php`. См.
[`examples/README.md`](examples/README.md).

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Запускает validate → normalize → require-checker → cs → psalm → тесты (включая
property-тесты). Также доступны `make build`, `make test`, `make mutation`,
`make release-check`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
