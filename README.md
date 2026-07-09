# rasuvaeff/yii3-telemetry

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-telemetry.svg)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-telemetry/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/yii3-telemetry/actions)
[![Psalm Level](https://shepherd.dev/github/rasuvaeff/yii3-telemetry/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-telemetry)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-telemetry/php)](https://packagist.org/packages/rasuvaeff/yii3-telemetry)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-telemetry.svg)](https://github.com/rasuvaeff/yii3-telemetry/blob/master/LICENSE.md)

Vendor-neutral tracing core for Yii3. One ergonomic call — `trace(name, callback)`
— opens a span, runs your code, and closes the span, instead of the verbose
OpenTelemetry span-builder. The exporter is a swappable backend.

> Using an AI coding assistant? [llms.txt](llms.txt) has a compact API reference
> you can pass as context.

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

`end()` is idempotent.

### Span

`SpanInterface` is what the callback receives:

| Method | Purpose |
|---|---|
| `setAttribute(string $key, bool\|int\|float\|string\|array\|null $value)` | attach a key/value |
| `updateName(string $name)` | rename the span |
| `setStatus(SpanStatusCode $code, ?string $description = null)` | set status |
| `recordException(\Throwable $e)` | record an exception |
| `end()` | finish (idempotent) |
| `isRecording()` | `false` for non-recording spans |
| `getTraceContext()` | the span's `TraceContext` |

The concrete `Span` (recorded by `LogTracer`) additionally exposes getters:
`getName()`, `getKind()`, `getStatus(): SpanStatus` (a value object pairing a
`SpanStatusCode` with an optional description), `getAttributes()`,
`getRecordedExceptions()`, `getDurationNanos()`, `hasEnded()`.

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
| `HttpClientSpanDecorator` | a PSR-18 client | `HTTP <method>` (+ `traceparent` injected) |
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

// DB (yiisoft/db)
$connection->setProfiler(new DbQueryProfiler($tracer));

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
