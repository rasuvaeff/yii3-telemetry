<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle handler-stack middleware: the {@see HttpClientSpanDecorator} equivalent
 * for `guzzlehttp/guzzle` (an optional dependency — never required by this
 * package).
 *
 * One CLIENT span is opened per call that reaches the handler it wraps and is
 * ended when the returned promise settles, not when the handler returns — so
 * `requestAsync()`, `Pool` and `Each::ofLimit()` calls are traced correctly.
 * Fulfilled: status code attribute, 4xx/5xx marked as error. Rejected: exception
 * recorded, status Error (plus the status code when the exception carries a
 * response). Attributes and error marking are shared with the PSR-18 decorator.
 *
 * Position in the stack decides the granularity: pushed *inside* a retry
 * middleware (after it in `push()` order) it yields one span per attempt,
 * pushed *outside* it yields one span per logical call.
 *
 * ```php
 * $stack = HandlerStack::create();
 * $stack->push(GuzzleTracingMiddleware::create($tracer), 'tracing');
 * ```
 *
 * @api
 */
final readonly class GuzzleTracingMiddleware
{
    /**
     * @return callable(callable(RequestInterface, array): PromiseInterface): callable(RequestInterface, array): PromiseInterface
     */
    public static function create(
        TracerInterface $tracer,
        PropagationPolicy $propagation = new PropagationPolicy(),
        TraceContextPropagator $propagator = new TraceContextPropagator(),
    ): callable {
        return static fn(callable $handler): callable
            => static function (RequestInterface $request, array $options) use ($handler, $tracer, $propagation, $propagator): PromiseInterface {
                $span = $tracer->startSpan(
                    name: HttpSpanSupport::spanName($request),
                    attributes: HttpSpanSupport::requestAttributes($request),
                    traceKind: TraceKind::Client,
                );

                try {
                    if ($propagation->allows($request->getUri())) {
                        $request = $propagator->inject($span->getTraceContext(), $request);
                    }

                    $promise = $handler($request, $options);
                } catch (\Throwable $exception) {
                    self::fail($span, $exception);

                    throw $exception;
                }

                return $promise->then(
                    static function (mixed $response) use ($span): mixed {
                        if ($response instanceof ResponseInterface) {
                            HttpSpanSupport::recordStatus($span, $response->getStatusCode());
                        }

                        $span->end();

                        return $response;
                    },
                    static function (mixed $reason) use ($span): PromiseInterface {
                        if ($reason instanceof BadResponseException) {
                            HttpSpanSupport::recordStatus($span, $reason->getResponse()->getStatusCode());
                        }

                        if ($reason instanceof \Throwable) {
                            self::fail($span, $reason);
                        } else {
                            $span->setStatus(SpanStatusCode::Error, 'Request rejected');
                            $span->end();
                        }

                        return Create::rejectionFor($reason);
                    },
                );
            };
    }

    private static function fail(SpanInterface $span, \Throwable $exception): void
    {
        $span->recordException($exception);
        $span->setStatus(SpanStatusCode::Error, $exception->getMessage());
        $span->end();
    }
}
