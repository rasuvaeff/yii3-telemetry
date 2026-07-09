# Examples

Runnable examples for `rasuvaeff/yii3-telemetry`. Each is self-contained and
needs no external services.

| Script | Shows | Needs server? |
|---|---|---|
| `01_basic_trace.php` | One span with attributes via `LogTracer`, PSR-3 output | no |
| `02_nested_trace.php` | Nested `trace()` — child inherits the parent `traceId` | no |
| `03_propagation.php` | W3C `traceparent` inject (outgoing) / extract (incoming) | no |

## Running

Install dependencies once, then run any script with PHP 8.3+:

```bash
composer install
php examples/01_basic_trace.php
php examples/02_nested_trace.php
php examples/03_propagation.php
```

Or via Docker (no PHP on the host):

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/01_basic_trace.php
```

`03_propagation.php` uses `nyholm/psr7` (a dev dependency) for PSR-7 messages.
In your own app, any PSR-7 / PSR-17 implementation works.
