# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.1 — 2026-07-25

- Reject trailing newlines in W3C trace/span id and flag patterns: anchor with
  `\z` instead of `$` in `TraceContext` and `TraceContextPropagator` (PCRE `$`
  matches before a trailing `\n`, which let a smuggled `"<hex>\n"` field pass
  format validation).

## 1.1.0 — 2026-07-25

- Ship an AI agent skill (`resources/skills/rasuvaeff-yii3-telemetry/SKILL.md` +
  `extra.skills` in composer.json): projects using the `llm/skills` Composer
  plugin get the skill synced into `.agents/skills/` automatically on install.
- Bump `rasuvaeff/property-testing` dev dependency to `^2.6`.
- Make property-test generator methods `public static` (private ones are removed
  by rector's `RemoveUnusedPrivateMethodRector` — they are called via reflection
  only).

## 1.0.0 — 2026-07-10

- Tracing core: `Tracer` facade with the frozen `trace(name, callback, attributes,
  scoped, traceKind, startNanos)` contract; `TracerInterface`,
  `TracerProviderInterface`. `startNanos` backdates a span (worker receive time,
  queue enqueue time).
- Tracers: `NullTracer` / `NullTracerProvider` (no-op) and `LogTracer` (PSR-3 dev
  tracer with an active-span stack and W3C id generation).
- Span model: `SpanInterface` (incl. `addEvent()` — timestamped OTel span
  events), mutable `Span` (+ `SpanEvent`), non-recording `NullSpan`, `TraceKind`,
  `SpanStatus` / `SpanStatusCode` — mirroring `open-telemetry/api`.
- `TraceContext` (W3C) with validation and `TraceContextPropagator`
  (extract from incoming server request, inject into outgoing client request;
  carrier-agnostic `toHeaders()`/`fromHeaders()` for queues and other non-HTTP
  transports; future traceparent versions parsed per W3C).
- `ClockInterface` (extends PSR-20) and `SystemClock` (wall + monotonic clocks).
- `TracerInterface::startSpan()` — a manual recording span (not activated; caller
  ends it) for split begin/end instrumentation.
- Instrumentation (backend-agnostic, calls the facade):
  `HttpClientSpanDecorator` (PSR-18), `TracingCacheDecorator` (PSR-16),
  `DbQueryProfiler` (`yiisoft/db` profiler, parameterized SQL only),
  `ViewRenderSpanListener` (`yiisoft/view` PSR-14 events). Their subsystem wiring
  is app-side (never unconditional in core `di.php`).
- `yiisoft/config` wiring: core binds only the facade; the backend or app owns
  `TracerProviderInterface`.
- Property tests (traceparent round-trip, span duration, attribute round-trip)
  and a `ConfigWiringTest` guarding the DI contract.
- `TraceContextLogger` — PSR-3 decorator adding `trace_id`/`span_id` to log
  context inside an active trace (pass-through otherwise; never overwrites
  caller-provided keys).
- `TraceIdResponseHeaderMiddleware` — opt-in PSR-15 middleware exposing the
  current trace id as a response header (default `X-Trace-Id`).
