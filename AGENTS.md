# AGENTS.md — yii3-telemetry

Guidance for AI agents working on this package. Read before changing code.

## What this is

The vendor-neutral **tracing core** of the Yii3 observability stack. It exposes
an ergonomic facade — `trace(name, callback)` opens a span, runs the callback,
closes the span — instead of the verbose OpenTelemetry span-builder. No exporter
lives here; a backend (`yii3-telemetry-otel`) supplies the real
`TracerProviderInterface`.

Namespace: `Rasuvaeff\Yii3Telemetry`.

Public API: `Tracer` (facade), `TracerInterface`, `TracerProviderInterface`,
`NullTracer`, `NullTracerProvider`, `LogTracer`, `SpanInterface`, `Span`,
`NullSpan`, `TraceKind`, `SpanStatus`, `SpanStatusCode`, `TraceContext`,
`TraceContextPropagator`, `ClockInterface`, `SystemClock`,
`Exception\InvalidArgumentException`. Instrumentation:
`HttpClientSpanDecorator` (PSR-18), `TracingCacheDecorator` (PSR-16),
`DbQueryProfiler` (`yiisoft/db`), `ViewRenderSpanListener` (`yiisoft/view`),
`TraceContextLogger` (PSR-3 log correlation), `TraceIdResponseHeaderMiddleware`
(PSR-15, opt-in trace-id response header).

**Core depends on `open-telemetry/api`** (thin: interfaces + `NoopTracer`, no
SDK). Types are *not* a parallel model — they mirror OTel so a backend adapter
maps them with no lookup table:

- `TraceKind` backing value == `OpenTelemetry\API\Trace\SpanKind::KIND_*` (0..4);
- `SpanStatusCode` backing value == `StatusCode::STATUS_*` (`Unset/Ok/Error`);
- `TraceContext` carries the W3C fields (`traceId` hex32, `spanId` hex16,
  `traceFlags` int, `traceState`).

The ergonomic facade (`Tracer`, `trace()`), `Span`, `Null*`/`LogTracer`,
`SystemClock`, and `TraceContextPropagator` are our own — that is the value core
adds over the OTel API.

## DI wiring (mirror of the core+backend rule)

`config/di.php` binds **only** the facade: `Tracer` and `TracerInterface =>
Tracer`. It must **never** bind `TracerProviderInterface` — that is the swappable
key, owned by exactly one source: a backend (`yii3-telemetry-otel`) or the app
(`TracerProviderInterface => NullTracerProvider` for config-only no-op). Two
vendor packages binding it in the `di` group is a `yiisoft/config` `Duplicate
key` error, by design. Without a provider binding, `Tracer` does not resolve —
intentional (mirrors `yiisoft/cache`).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **The `trace()` contract is frozen at 1.0.0** (roave/bc-check enforces it):
   - callback returns → span ends with its current status, value returned;
   - callback throws → `recordException()`, status `Error`, span ends, original
     exception **re-thrown** (never swallowed);
   - `scoped: true` → span is `currentSpan()` during the callback, restored after;
   - nested `trace()` inherits the parent `traceId`;
   - a dropped/disabled span still runs the callback; `currentSpan()` then returns
     a non-recording span, **never `null`**. `end()` is idempotent.
   `TracerInterface` also has `startSpan()` — a manual recording span (NOT
   activated; the caller ends it) for split begin/end instrumentation. Both
   `trace()` and `startSpan()` accept `?int $startNanos` (backdate the span
   start; with an explicit start the core `Span` duration is wall-clock based).
   `SpanInterface` includes `addEvent(name, attributes)` (timestamped OTel span
   events). On success a span keeps status Unset (never auto-`Ok`). Do not add
   further methods to `SpanInterface`/`TracerInterface` after the 1.0.0 tag —
   every impl (Null/Log/Otel + third-party) must stay in sync.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer bench
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make psalm`, `make test`,
`make test-coverage`, `make mutation`, `make release-check`.
`make test-coverage` and `make mutation` bootstrap `pcov` inside the container.
`composer.lock` is gitignored (library).

## Invariants & gotchas

- **Two clocks, never mixed.** `ClockInterface` extends PSR-20 (`now()` = wall,
  ~µs) and adds `monotonicNanos()` (`hrtime`, for durations). `Span` uses `now()`
  for the start timestamp and monotonic delta for the duration.
- `TraceContext` validates format in its constructor (hex32 / hex16 / flags
  0..255) but allows the all-zero **invalid** sentinel; `isValid()` additionally
  requires non-zero ids (W3C).
- `TraceContextPropagator`: `extract` reads `traceparent` from an incoming
  `ServerRequestInterface`; `inject` writes it onto an outgoing `RequestInterface`
  (never a response). `toHeaders`/`fromHeaders` are the carrier-agnostic pair for
  non-HTTP transports (queues). Malformed / `ff`-version / all-zero → invalid;
  future traceparent versions are parsed from their first four fields (W3C),
  version `00` requires exactly four.
- `config/di.php` and `config/params.php` are outside the cs/psalm/testo gate.
  `ConfigWiringTest` guards them (facade keys present, `TracerProviderInterface`
  absent, no-op resolution). Verify wiring changes there, not via the build gate.
- **Instrumentation wiring is app-side, NEVER unconditional in core `di.php`**
  (`ProfilerInterface => DbQueryProfiler`, the view listener, the decorators):
  the container would fatal if the subsystem isn't installed. `HttpClientSpanDecorator`
  (PSR-18) and `TracingCacheDecorator` (PSR-16) are pure-PSR (hard `require`);
  `DbQueryProfiler`/`ViewRenderSpanListener` reference `yiisoft/db`/`yiisoft/view`
  symbols (optional `suggest` + `require-dev`), whitelisted in
  `composer-require-checker.json` — a sanctioned optional-soft-dep declaration,
  NOT a suppression. Do not delete that file. `db.statement` uses **parameterized**
  SQL (`context->asArray()['sql']`), never the value-substituted token.
- `TraceContextLogger` never overwrites caller-provided `trace_id`/`span_id`
  keys and is a pass-through without a valid context. `TraceIdResponseHeaderMiddleware`
  must sit AFTER (inside) the tracing middleware — it reads the context after
  `handle()`, which is only valid while the root span is still active.
- **SQL debug features are deferred, not forgotten**: the plan's
  `TELEMETRY_SQL_PARAMS` debug mode, secret masking, and slow-query threshold are
  deliberately NOT in 1.0.0 — parameter values are never attached to a span at
  all (safer than masking). If added later, they must be opt-in, off by default.
- **Queue instrumentation is deferred**: `yiisoft/queue` has no stable release
  (dev-master only); do NOT add `minimum-stability: dev` to this core for it.
  The supported path today is documented in the README: propagate with
  `toHeaders()`/`fromHeaders()` and open a Consumer span with
  `trace(traceKind: Consumer, startNanos: <enqueue time>)`.
- Code: `declare(strict_types=1)`, `final readonly class` (or `final class` when
  a static/singleton or mutable state is needed), `#[\Override]`, explicit types.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert to
  floating `@vN` tags. Updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.
- `mbstring` is in the CI extension list for every job (property-testing needs
  it), but `ext-mbstring` is **not** a runtime `require` — `src/` uses no `mb_*`.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build`; if the change affects the public API or release
  safety, also run `make release-check`. Paste the output.
