# rasuvaeff/yii3-telemetry

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry.svg)](https://github.com/rasuvaeff/yii3-telemetry/blob/master/LICENSE.md)
[Русская версия](README.ru.md)

Vendor-neutral tracing core for Yii3. One ergonomic call — `trace(name, callback)`
— opens a span, runs your code, and closes the span, instead of the verbose
OpenTelemetry span-builder. The exporter is a swappable backend.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference
> you can pass as context.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer
> plugin also get this package's agent skill synced into `.agents/skills/`
> automatically on install.

## Requirements

- PHP 8.3+ (64-bit — epoch nanoseconds exceed `PHP_INT_MAX` on 32-bit builds)
- `open-telemetry/api` ^1.5 (thin: interfaces + `NoopTracer`, no SDK)
- PSR-20 clock, PSR-3 log, PSR-7 http-message interfaces

## Installation

```bash
composer require rasuvaeff/yii3-telemetry
```

For real span export, add a backend (Sprint 2): `rasuvaeff/yii3-telemetry-otel`.
Without one, bind the no-op provider (see [Wiring](#wiring-yiisoftconfig)).

## Usage

### Trace a block of work

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

The callback receives the active `SpanInterface` and its return value becomes the
`trace()` return value.

### `trace()` contract (frozen at 1.0.0)

| Situation | Behaviour |
|---|---|
| callback returns a value | span ends with its current status; value returned |
| callback throws | `recordException()`, status `Error`, span ends, the original exception is **re-thrown** |
| `scoped: true` (default) | span is `currentSpan()` during the callback; the previous span is restored after |
| nested `trace()` | the child inherits the parent's `traceId`, gets its own `spanId` |
| span dropped / tracing disabled | callback still runs; `currentSpan()` returns a **non-recording** span, never `null` |
| `startNanos: <int>` | backdates the span start (unix-epoch nanoseconds) for work that logically began earlier — a worker receive timestamp, a queue enqueue time; `null` (default) = now |

`end()` is idempotent.

### Span

`SpanInterface` is what the callback receives:

| Method | Purpose |
|---|---|
| `setAttribute(string $key, bool\|int\|float\|string\|array\|null $value)` | attach a key/value |
| `updateName(string $name)` | rename the span |
| `setStatus(SpanStatusCode $code, ?string $description = null)` | set status |
| `addEvent(string $name, array $attributes = [])` | record a timestamped point-in-time annotation (an OTel span event: `retry`, `cache.miss`, …) |
| `recordException(\Throwable $e)` | record an exception |
| `end()` | finish (idempotent) |
| `isRecording()` | `false` for non-recording spans |
| `getTraceContext()` | the span's `TraceContext` |

The concrete `Span` (recorded by `LogTracer`) additionally exposes getters:
`getName()`, `getKind()`, `getStatus(): SpanStatus` (a value object pairing a
`SpanStatusCode` with an optional description), `getAttributes()`,
`getEvents(): list<SpanEvent>`, `getRecordedExceptions()`, `getDurationNanos()`,
`hasEnded()`.

### Tracers

| Class | Use |
|---|---|
| `Tracer` | DI facade; delegates to the active `TracerInterface` from the provider |
| `NullTracer` / `NullTracerProvider` | no-op; runs the callback with a non-recording span |
| `LogTracer` | dev tracer; records real spans and logs each finished span via PSR-3 |

```php
use Rasuvaeff\Yii3Telemetry\LogTracer;

$tracer = new LogTracer($psrLogger); // logs every finished span, no backend
```

### Context propagation (W3C Trace Context)

```php
use Rasuvaeff\Yii3Telemetry\TraceContextPropagator;

$propagator = new TraceContextPropagator();

// Incoming server request → context.
$context = $propagator->extract($serverRequest);

// Context → outgoing client request (adds the `traceparent` header).
$request = $propagator->inject($context, $clientRequest);
```

`extract` reads a `ServerRequestInterface`; `inject` writes an outgoing
`RequestInterface`. A missing/malformed header yields `TraceContext::invalid()`.

For non-HTTP transports use the carrier-agnostic pair — a plain header map you
can put into any envelope (queue message, AMQP header table, gRPC metadata):

```php
// Producer: attach the current context to the message envelope.
$envelope['headers'] = $propagator->toHeaders($tracer->getContext());

// Consumer: restore it and open a Consumer span.
$context = $propagator->fromHeaders($message['headers'] ?? []);
```

`fromHeaders` matches names case-insensitively; an invalid context yields an
empty map from `toHeaders`, so the round trip is always safe.

### Opting out of `traceparent` (third-party APIs)

`traceparent` is right for your own services and wrong for third-party APIs: an
unexpected header is a fingerprinting signal for scrapers, leaks an internal id
to an outside party, and some APIs reject unknown headers. The CLIENT span stays
either way — only the injection is configurable:

```php
new HttpClientSpanDecorator($client, $tracer, propagate: false);                  // span only
new HttpClientSpanDecorator($client, $tracer, propagateTo: ['api.internal', '*.svc.cluster.local']);
```

| `propagate` | `propagateTo` | Injects |
|---|---|---|
| `true` (default) | `[]` (default) | into every request (the 1.1 behaviour) |
| `true` | non-empty | only when the request host matches an entry |
| `false` | any | never |

An entry is an exact host (`api.internal`) or a leading wildcard
(`*.svc.cluster.local` — subdomains only, **not** the apex). Matching is
case-insensitive; an invalid entry throws `InvalidArgumentException`. The same
rule is the reusable `PropagationPolicy` (`new PropagationPolicy(enabled: true,
hosts: [...])`, `->allows($uriOrHost)`) that the Guzzle middleware uses.

### Guzzle

`GuzzleTracingMiddleware` is the `HandlerStack` equivalent of the PSR-18
decorator (`guzzlehttp/guzzle` is a `suggest` — never a hard requirement).
Wrapping a Guzzle client in a PSR-18 decorator loses `requestAsync()`/`Pool`
calls and hides retries; the middleware does neither:

```php
$stack = HandlerStack::create();
$stack->push(GuzzleTracingMiddleware::create($tracer), 'tracing');
// third-party API instead: keep the span, send no header
// $stack->push(GuzzleTracingMiddleware::create($tracer, new PropagationPolicy(enabled: false)));

$client = new Client(['handler' => $stack]);
```

- A CLIENT span is opened per call and ended when the **promise settles**, not
  when the handler returns — `requestAsync()`, `Pool` and `Each::ofLimit()` work.
- Same attributes and 4xx/5xx error marking as `HttpClientSpanDecorator` (one
  shared helper). A rejection records the exception and sets status Error; a
  `BadResponseException` also keeps `http.response.status_code`.
- `traceparent` carries the CLIENT span's own ids, subject to the
  `PropagationPolicy`.
- **Position decides granularity.** `push()`ed *inside* (after) a retry
  middleware you get one span per attempt, so retries are visible; pushed
  *outside* (before) it you get one span per logical call.

### Queues

`yiisoft/queue` has no tagged release yet, so the core ships **queue-agnostic**
primitives instead of a middleware: `QueueTracing` works on the plain message
metadata map and does not depend on any queue package.

- `inject(array $metadata, ?int $enqueuedAtNanos = null, ?int $availableAtNanos = null): array` —
  adds `traceparent`/`tracestate` of the current context and the enqueue time
  (`telemetry.enqueued_at_nanos`, unix-epoch ns; `now` by default). Pass
  `availableAtNanos` for a delayed message.
- `consume(array $metadata, string $name, callable $handler, array $attributes = []): mixed` —
  runs the handler in a CONSUMER span **per message**, parented to the producer
  (a fresh root without valid headers), ended before it returns or throws; an
  exception is recorded, sets status Error and is re-thrown.

```php
final readonly class TracingPushMiddleware implements MiddlewarePushInterface
{
    public function __construct(private QueueTracing $tracing) {}

    public function processPush(PushRequest $request, MessageHandlerPushInterface $handler): PushRequest
    {
        $message = $request->getMessage();
        $message = $message->withMetadata($this->tracing->inject($message->getMetadata()));

        return $handler->handlePush($request->withMessage($message));
    }
}

final readonly class TracingConsumeMiddleware implements MiddlewareConsumeInterface
{
    public function __construct(private QueueTracing $tracing) {}

    public function processConsume(ConsumeRequest $request, MessageHandlerConsumeInterface $handler): ConsumeRequest
    {
        $message = $request->getMessage();

        return $this->tracing->consume(
            $message->getMetadata(),
            'process ' . $message->getHandlerName(),
            static fn (): ConsumeRequest => $handler->handleConsume($request),
            ['messaging.system' => 'yii-queue'],
        );
    }
}
```

(The interface and method names above follow `yiisoft/queue` `master` and may
differ in your revision — the two `QueueTracing` calls are the part that matters.)
A ready-made `yiisoft/queue` middleware will follow its first tagged release.

Design notes:

- **Wait time is an attribute, not a span or a backdated start.** The span covers
  processing only, so its duration stays the handler time (alerts and latency
  percentiles on it are not polluted by an idle queue), and a trace still has
  exactly one span per message. Time in the queue is
  `messaging.message.queue_time_nanos` (now minus enqueue time; for a delayed
  message minus the later of enqueue and `availableAtNanos`, never negative) —
  filter or alert on it in the backend. Backdating `startNanos` would fold the
  wait into the duration; a separate wait span would double the span count.
- **Long-running workers.** `queue:listen` never ends, so a console-command root
  span must not become the parent of every job. `consume()` activates the
  producer's context (or an empty one) for the duration of the handler, so a job
  never nests under the command span or under the previous job.
- The producer is activated through the OpenTelemetry context API, so parenting
  needs the OTel backend (`yii3-telemetry-otel`); with another tracer the span is
  still emitted, as a root.
- Attributes follow the OpenTelemetry messaging conventions
  (`messaging.operation.type = process`); add `messaging.system`,
  `messaging.destination.name` or a retry-attempt attribute via `$attributes`
  (they win over the defaults). Messages pushed before tracing was enabled have
  no headers and simply start a fresh trace.

### Clock

`ClockInterface` extends PSR-20 with a monotonic reading — two clocks that must
not be mixed:

- `now(): \DateTimeImmutable` — the wall clock (span start timestamp);
- `monotonicNanos(): int` — `hrtime`, for measuring durations.

`SystemClock` is the default (and is a valid PSR-20 clock).

## Wiring (`yiisoft/config`)

The core `config/di.php` binds **only** the facade (`Tracer`, `TracerInterface`).
It never binds `TracerProviderInterface` — that swappable key is owned by exactly
one source. With no backend installed, bind the no-op provider in your app:

```php
// config/common/di.php
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;

return [
    TracerProviderInterface::class => NullTracerProvider::class,
];
```

Installing `yii3-telemetry-otel` provides the real binding instead — binding it
in two vendor packages is a deliberate `yiisoft/config` `Duplicate key` error.

## Instrumentation

Backend-agnostic instrumentation that records spans through the facade. Wire it
**app-side** — never unconditionally in a package `di.php`, or the container
would fatal when the subsystem isn't installed.

| Class | Wraps / listens to | Spans |
|---|---|---|
| `HttpClientSpanDecorator` | a PSR-18 client | `HTTP <method>` (+ `traceparent` injected unless opted out) |
| `GuzzleTracingMiddleware` | a Guzzle `HandlerStack` | `HTTP <method>` per attempt/call, async-safe |
| `QueueTracing` | any queue's push/consume hooks | `Consumer` span per message |
| `TracingCacheDecorator` | a PSR-16 cache | `cache.<op>` |
| `DbQueryProfiler` | `yiisoft/db` profiler | `db.query` (parameterized SQL only) |
| `ViewRenderSpanListener` | `yiisoft/view` PSR-14 events | `view.render` |
| `TraceContextLogger` | a PSR-3 logger | adds `trace_id`/`span_id` to log context |
| `TraceIdResponseHeaderMiddleware` | PSR-15 response | `X-Trace-Id` response header (opt-in) |

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

`DbQueryProfiler` and `ViewRenderSpanListener` bracket a subsystem's split
begin/end hooks with `Tracer::startSpan()` (a manual span the caller ends).
`yiisoft/db` and `yiisoft/view` are optional (`suggest`); their symbols are
declared in `composer-require-checker.json`.

### Log correlation & exposing the trace id

```php
// Wrap the application logger — every record inside an active trace gets
// trace_id / span_id in its context (existing keys are never overwritten):
$logger = new TraceContextLogger($innerLogger, $tracer);

// Opt-in: return the trace id to the client for support tickets. Place it
// AFTER the tracing middleware (inside the root span):
$middleware = new TraceIdResponseHeaderMiddleware($tracer);              // X-Trace-Id
$middleware = new TraceIdResponseHeaderMiddleware($tracer, 'Trace-Ref'); // custom name
```

Without an active valid trace context both are transparent: the log record and
the response pass through unchanged.

## Security

- **SQL safety**: `DbQueryProfiler` puts only the **parameterized** SQL into
  `db.statement` — parameter values are never attached to a span. A debug
  opt-in for parameter values and a slow-query threshold are deliberately not
  implemented; if they land later, they will be off by default.
- `TraceContext` validates ids (hex32 / hex16) and flags (0..255) in its
  constructor; malformed propagation headers are rejected, not trusted.
- `trace()` never swallows exceptions — failures stay visible.

## Examples

Runnable, server-independent scripts live in [`examples/`](examples/):
`01_basic_trace.php`, `02_nested_trace.php`, `03_propagation.php`. See
[`examples/README.md`](examples/README.md).

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Runs validate → normalize → require-checker → cs → psalm → tests (incl.
property tests). `make build`, `make test`, `make mutation`, `make release-check`
are also available.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
### Clock

`ClockInterface` extends PSR-20 with a monotonic reading — two clocks that must
not be mixed:

- `now(): \DateTimeImmutable` — the wall clock (span start timestamp);
- `monotonicNanos(): int` — `hrtime`, for measuring durations.

`SystemClock` is the default (and is a valid PSR-20 clock).

## Wiring (`yiisoft/config`)

The core `config/di.php` binds **only** the facade (`Tracer`, `TracerInterface`).
It never binds `TracerProviderInterface` — that swappable key is owned by exactly
one source. With no backend installed, bind the no-op provider in your app:

```php
// config/common/di.php
use Rasuvaeff\Yii3Telemetry\NullTracerProvider;
use Rasuvaeff\Yii3Telemetry\TracerProviderInterface;

return [
    TracerProviderInterface::class => NullTracerProvider::class,
];
```

Installing `yii3-telemetry-otel` provides the real binding instead — binding it
in two vendor packages is a deliberate `yiisoft/config` `Duplicate key` error.

## Instrumentation

Backend-agnostic instrumentation that records spans through the facade. Wire it
**app-side** — never unconditionally in a package `di.php`, or the container
would fatal when the subsystem isn't installed.

| Class | Wraps / listens to | Spans |
|---|---|---|
| `HttpClientSpanDecorator` | a PSR-18 client | `HTTP <method>` (+ `traceparent` injected unless opted out) |
| `GuzzleTracingMiddleware` | a Guzzle `HandlerStack` | `HTTP <method>` per attempt/call, async-safe |
| `QueueTracing` | any queue's push/consume hooks | `Consumer` span per message |
| `TracingCacheDecorator` | a PSR-16 cache | `cache.<op>` |
| `DbQueryProfiler` | `yiisoft/db` profiler | `db.query` (parameterized SQL only) |
| `ViewRenderSpanListener` | `yiisoft/view` PSR-14 events | `view.render` |
| `TraceContextLogger` | a PSR-3 logger | adds `trace_id`/`span_id` to log context |
| `TraceIdResponseHeaderMiddleware` | PSR-15 response | `X-Trace-Id` response header (opt-in) |

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

`DbQueryProfiler` and `ViewRenderSpanListener` bracket a subsystem's split
begin/end hooks with `Tracer::startSpan()` (a manual span the caller ends).
`yiisoft/db` and `yiisoft/view` are optional (`suggest`); their symbols are
declared in `composer-require-checker.json`.

### Log correlation & exposing the trace id

```php
// Wrap the application logger — every record inside an active trace gets
// trace_id / span_id in its context (existing keys are never overwritten):
$logger = new TraceContextLogger($innerLogger, $tracer);

// Opt-in: return the trace id to the client for support tickets. Place it
// AFTER the tracing middleware (inside the root span):
$middleware = new TraceIdResponseHeaderMiddleware($tracer);              // X-Trace-Id
$middleware = new TraceIdResponseHeaderMiddleware($tracer, 'Trace-Ref'); // custom name
```

Without an active valid trace context both are transparent: the log record and
the response pass through unchanged.

## Security

- **SQL safety**: `DbQueryProfiler` puts only the **parameterized** SQL into
  `db.statement` — parameter values are never attached to a span. A debug
  opt-in for parameter values and a slow-query threshold are deliberately not
  implemented; if they land later, they will be off by default.
- `TraceContext` validates ids (hex32 / hex16) and flags (0..255) in its
  constructor; malformed propagation headers are rejected, not trusted.
- `trace()` never swallows exceptions — failures stay visible.

## Examples

Runnable, server-independent scripts live in [`examples/`](examples/):
`01_basic_trace.php`, `02_nested_trace.php`, `03_propagation.php`. See
[`examples/README.md`](examples/README.md).

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Runs validate → normalize → require-checker → cs → psalm → tests (incl.
property tests). `make build`, `make test`, `make mutation`, `make release-check`
are also available.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
