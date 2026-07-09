# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 — Unreleased

- Tracing core: `Tracer` facade with the frozen `trace(name, callback, attributes,
  scoped, traceKind)` contract; `TracerInterface`, `TracerProviderInterface`.
- Tracers: `NullTracer` / `NullTracerProvider` (no-op) and `LogTracer` (PSR-3 dev
  tracer with an active-span stack and W3C id generation).
- Span model: `SpanInterface`, mutable `Span`, non-recording `NullSpan`,
  `TraceKind`, `SpanStatus` / `SpanStatusCode` — mirroring `open-telemetry/api`.
- `TraceContext` (W3C) with validation and `TraceContextPropagator`
  (extract from incoming server request, inject into outgoing client request).
- `ClockInterface` (extends PSR-20) and `SystemClock` (wall + monotonic clocks).
- `yiisoft/config` wiring: core binds only the facade; the backend or app owns
  `TracerProviderInterface`.
- Property tests (traceparent round-trip, span duration, attribute round-trip)
  and a `ConfigWiringTest` guarding the DI contract.
