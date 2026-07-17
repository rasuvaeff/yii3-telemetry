# rasuvaeff/yii3-телеметрия
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry.svg)](https://github.com/rasuvaeff/yii3-telemetry/blob/master/LICENSE.md)
Независимое от поставщика ядро ​​трассировки для Yii3. Один эргономичный вызов — `trace(name, callback)`
 — открывает диапазон, запускает ваш код и закрывает диапазон вместо подробного построителя спанов
 OpenTelemetry. Экспортер — это заменяемый бэкэнд.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) имеет компактную ссылку на API
 > которую можно передать в качестве контекста. @@ЛИНИЯ@@
## Требования
- PHP 8.3+ (64-разрядная версия — наносекунды эпохи превышают `PHP_INT_MAX` в 32-битных сборках)
 - `open-telemetry/api` ^1.5 (тонкая версия: интерфейсы + `NoopTracer`, без SDK)
 - часы PSR-20, журнал PSR-3, интерфейсы http-сообщений PSR-7

## Установка
```bash
composer require rasuvaeff/yii3-telemetry
```
Для реального экспорта диапазона добавьте бэкэнд (Sprint 2): `rasuvaeff/yii3-telemetry-otel`.
 Без него привяжите неактивного провайдера (см. [Проводка](#wiring-yiisoftconfig)). @@ЛИНИЯ@@
## Использование
### Отслеживание блока работы
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
Обратный вызов получает активный `SpanInterface`, и его возвращаемое значение становится возвращаемым значением
 `trace()`. @@ЛИНИЯ@@
### Контракт `trace()` (заморожен в версии 1.0.0)
| Ситуация | Поведение |
 |---|---|
 | обратный вызов возвращает значение | диапазон заканчивается своим текущим статусом; возвращенное значение |
 | обратный вызов бросает | `recordException()`, статус `Error`, диапазон заканчивается, исходное исключение **повторно генерируется** |
 | `scoped: true` (по умолчанию) | во время обратного вызова диапазон равен `currentSpan()`; предыдущий диапазон восстанавливается после |
 | вложенный `trace()` | дочерний элемент наследует "traceId" родителя, получает свой собственный "spanId" |
 | диапазон удален/отслеживание отключено | обратный вызов все еще выполняется; `currentSpan()` возвращает диапазон **без записи**, а не `null` |
 | `startNanos: <int>` | устанавливает заднюю дату начала промежутка (наносекунды эпохи UNIX) для работы, которая логически началась раньше — рабочий получает временную метку, время постановки в очередь; `null` (по умолчанию) = сейчас |

 `end()` является идемпотентным. @@ЛИНИЯ@@
### Охватывать
`SpanInterface` — это то, что получает обратный вызов:

 | Метод | Цель |
 |---|---|
 | `setAttribute(string $key, bool\|int\|float\|string\|array\|null $value)` | прикрепить ключ/значение |
 | `updateName(строка $name)` | переименовать диапазон |
 | `setStatus(SpanStatusCode $code, ?string $description = null)` | установить статус |
 | `addEvent(строка $name, массив $attributes = [])` | записать аннотацию к определенному моменту времени с отметкой времени (событие диапазона OTel: `retry`, `cache.miss`, …) |
 | `recordException(\Throwable $e)` | записать исключение |
 | `конец()` | закончить (идемпотент) |
 | `isRecording()` | `false` для промежутков без записи |
 | `getTraceContext()` | `TraceContext` диапазона |

 Конкретный `Span` (записанный `LogTracer`) дополнительно предоставляет геттеры:
 `getName()`, `getKind()`, `getStatus(): SpanStatus` (объект значения, соединяющий
 `SpanStatusCode` с необязательным описанием), `getAttributes()`,
 `getEvents(): list<SpanEvent>`, `getRecordedExceptions()`, `getDurationNanos()`,
 `hasEnded()`. @@ЛИНИЯ@@
### Трейсеры
| Класс | Использование |
 |---|---|
 | `Трейсер` | Фасад ДИ; делегирует активный интерфейс TracerInterface от провайдера |
 | `NullTracer` / `NullTracerProvider` | нет операции; запускает обратный вызов с интервалом без записи |
 | `LogTracer` | трассировщик разработчиков; записывает реальные пролеты и регистрирует каждый законченный пролет через PSR-3 | @@ЛИНИЯ@@
```php
use Rasuvaeff\Yii3Telemetry\LogTracer;

$tracer = new LogTracer($psrLogger); // logs every finished span, no backend
```
### Распространение контекста (контекст трассировки W3C)
```php
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;

$propagator = new TraceContextPropagator();

// Incoming server request → context.
$context = $propagator->extract($serverRequest);

// Context → outgoing client request (adds the `traceparent` header).
$request = $propagator->inject($context, $clientRequest);
```
`extract` считывает `ServerRequestInterface`; `inject` записывает исходящий
 `RequestInterface`. Отсутствующий/неверный заголовок дает `TraceContext::invalid()`.

 Для транспорта, отличного от HTTP, используйте пару, не зависящую от оператора связи — простую карту заголовка, которую вы
 можете поместить в любой конверт (сообщение очереди, таблица заголовков AMQP, метаданные gRPC):

```php
// Producer: attach the current context to the message envelope.
$envelope['headers'] = $propagator->toHeaders($tracer->getContext());

// Consumer: restore it and open a Consumer span.
$context = $propagator->fromHeaders($message['headers'] ?? []);
```
`fromHeaders` сопоставляет имена без учета регистра; неверный контекст дает пустую карту
 из `toHeaders`, поэтому обратный путь всегда безопасен.

 > **Дорожная карта инструментирования очереди.** Готовое промежуточное программное обеспечение `yiisoft/queue`
 > (Producer inject + Consumer span) откладывается до тех пор, пока `yiisoft/queue` не получит
 > стабильную версию — API-интерфейс несущей, описанный выше, является поддерживаемым способом распространения
 > трассировки через любую очередь сегодня. @@ЛИНИЯ@@
### Часы
`ClockInterface` расширяет PSR-20 с помощью монотонного чтения — два часа, которые
 нельзя смешивать:

 - `now(): \DateTimeImmutable` — настенные часы (отметка времени начала интервала);
 - `monotonicNanos(): int` — `hrtime`, для измерения длительности.

 `SystemClock` используется по умолчанию (и является действительным тактовым сигналом PSR-20). @@ЛИНИЯ@@
## Проводка (`yiisoft/config`)
Ядро `config/di.php` связывает **только** фасад (`Tracer`, `TracerInterface`).
 Он никогда не связывает `TracerProviderInterface` — этот заменяемый ключ принадлежит ровно
 одному источнику. Если серверная часть не установлена, привяжите неактивного провайдера в своем приложении:

```php
// config/common/di.php
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;

return [
    TracerProviderInterface::class => NullTracerProvider::class,
];
```
Вместо этого установка `yii3-telemetry-otel` обеспечивает реальную привязку — привязка
 в двух пакетах поставщиков является преднамеренной ошибкой `yiisoft/config` `Duplate key`. @@ЛИНИЯ@@
## Инструментарий
Независимый от серверной части инструментарий, который записывает промежутки через фасад. Подключите его
 **на стороне приложения** — никогда безоговорочно в пакете `di.php`, иначе контейнер
 может привести к фатальному исходу, если подсистема не установлена.

 | Класс | Заворачивает/слушает | Пролеты |
 |---|---|---|
 | `HttpClientSpanDecorator` | клиент PSR-18 | `HTTP <метод>` (+ введенный `traceparent`) |
 | `TracingCacheDecorator` | кэш PSR-16 | `кэш.<op>` |
 | `DbQueryProfiler` | профилировщик `yiisoft/db` | `db.query` (только параметризованный SQL) |
 | `ViewRenderSpanListener` | `yiisoft/view` События PSR-14 | `view.render` |
 | `TraceContextLogger` | регистратор ПСР-3 | добавляет `trace_id`/`span_id` в контекст журнала |
 | `TraceIdResponseHeaderMiddleware` | Ответ PSR-15 | Заголовок ответа `X-Trace-Id` (по желанию) | @@ЛИНИЯ@@
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
`DbQueryProfiler` и `ViewRenderSpanListener` заключают в скобки разделенные
 перехватчики начала/конца подсистемы с помощью `Tracer::startSpan()` (ручной диапазон, который завершает вызывающий объект).
 `yiisoft/db` и `yiisoft/view` являются необязательными («предлагают»); их символы
 объявлены в `composer-require-checker.json`. @@ЛИНИЯ@@
### Корреляция журналов и раскрытие идентификатора трассировки
```php
// Wrap the application logger — every record inside an active trace gets
// trace_id / span_id in its context (existing keys are never overwritten):
$logger = new TraceContextLogger($innerLogger, $tracer);

// Opt-in: return the trace id to the client for support tickets. Place it
// AFTER the tracing middleware (inside the root span):
$middleware = new TraceIdResponseHeaderMiddleware($tracer);              // X-Trace-Id
$middleware = new TraceIdResponseHeaderMiddleware($tracer, 'Trace-Ref'); // custom name
```
Без активного действительного контекста трассировки оба прозрачны: запись журнала и
 ответ проходят без изменений. @@ЛИНИЯ@@
## Безопасность
- **Безопасность SQL**: `DbQueryProfiler` помещает в
 `db.statement` только **параметризованный** SQL — значения параметров никогда не прикрепляются к диапазону. Возможность отладки
 для значений параметров и порог медленного запроса
 намеренно не реализованы; если они приземлятся позже, они будут отключены по умолчанию.
 — `TraceContext` проверяет идентификаторы (hex32/hex16) и флаги (0..255) в своем конструкторе
; неправильно сформированные заголовки распространения отклоняются, им не доверяют.
 — `trace()` никогда не проглатывает исключения — сбои остаются видимыми. @@ЛИНИЯ@@
## Примеры
Выполняемые, независимые от сервера сценарии находятся в [`examples/`](examples/):
 `01_basic_trace.php`, `02_nested_trace.php`, `03_propagation.php`. См.
 [`examples/README.md`](examples/README.md). @@ЛИНИЯ@@
## Разработка
```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```
Запускает проверку → нормализацию → require-checker → cs → psalm → тесты (включая тесты свойств
). `make build`, `make test`, `makemutation`, `make Release-check`
 также доступны. @@ЛИНИЯ@@
## Лицензия
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
