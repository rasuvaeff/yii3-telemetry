---
name: rasuvaeff-yii3-telemetry
description: >-
  Vendor-neutral tracing core for Yii3 — Tracer facade with trace(name,
  callback), SpanInterface, TraceContext (W3C), TraceContextPropagator,
  TraceContextLogger, instrumentation decorators (PSR-18/PSR-16/yiisoft-db/view).
  Use when writing, reviewing or debugging tracing, span creation, trace-context
  propagation or trace-id log correlation in a project that has this package
  installed. Real export needs the yii3-telemetry-otel backend.
---

# rasuvaeff/yii3-telemetry

Ergonomic `trace(name, callback)` facade over a swappable
OpenTelemetry-compatible backend. Core exports nothing itself — a backend
(`rasuvaeff/yii3-telemetry-otel`) binds the exporter. Namespace
`Rasuvaeff\Yii3Telemetry\`.

## Safety rules — verify these on every change

1. **Never bind `TracerProviderInterface` in this package's `config/di.php`.**
   Core binds only the facade (`Tracer`, `TracerInterface`). Exactly one source
   owns the provider — the backend or the app — otherwise `yiisoft/config`
   fails with `Duplicate key`. Without a backend, the app binds
   `TracerProviderInterface => NullTracerProvider` (no-op).

2. **The `trace()` contract is frozen.** Callback throws → `recordException()`
   + status `Error` + span ends + exception **re-thrown**, never swallowed.
   `currentSpan()` is never `null` (a disabled span is non-recording).
   `end()` is idempotent. Do not add methods to `SpanInterface` /
   `TracerInterface` — every impl (Null/Log/Otel) must stay in sync.

3. **Never put sensitive data in span attributes.** `db.statement` carries
   parameterized SQL only — parameter values are never attached to a span.
   Do not add request bodies, tokens or PII as attributes.

4. **Two clocks, never mixed.** `now()` (PSR-20 wall clock) is for timestamps;
   `monotonicNanos()` (hrtime) is for durations. Computing a duration from
   wall-clock timestamps is a bug.

5. **Propagation is W3C `traceparent`.** `extract()` reads an incoming
   `ServerRequestInterface`; `inject()` writes an outgoing `RequestInterface`
   (never a response). For queues use `toHeaders()` / `fromHeaders()`.
   `TraceIdResponseHeaderMiddleware` must sit AFTER (inside) the tracing
   middleware, or the context is already gone.

## Canonical usage

```php
use Rasuvaeff\Yii3Telemetry\{Tracer, SpanInterface, TraceKind};

$value = $tracer->trace(
    name: 'checkout.process',
    callback: static fn (SpanInterface $span): string => 'ok',
    attributes: ['user.id' => '7'],
    traceKind: TraceKind::Internal,
);

$tracer->currentSpan();  // SpanInterface, never null
$tracer->getContext();   // TraceContext (invalid if none active)
```

For split begin/end (DB queries, view rendering) use
`$tracer->startSpan(...)` — not activated, the caller ends it. For trace ids
in logs decorate PSR-3 with `TraceContextLogger` (adds `trace_id`/`span_id`,
never overwrites caller-provided keys).

## Full API

The complete reference — span methods, `TraceContext` validation, propagator
carriers, clocks, all instrumentation decorators — ships with the package:
read `vendor/rasuvaeff/yii3-telemetry/llms.txt` before guessing a method name.
