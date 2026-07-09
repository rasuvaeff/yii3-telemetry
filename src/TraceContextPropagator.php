<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * W3C Trace Context propagation.
 *
 * - {@see extract()} reads `traceparent`/`tracestate` from an **incoming**
 *   {@see ServerRequestInterface}. A missing or malformed header yields
 *   {@see TraceContext::invalid()}.
 * - {@see inject()} writes them onto an **outgoing** client
 *   {@see RequestInterface}. An invalid context is left untouched.
 *
 * (Injecting into a response is a separate `traceresponse`/Server-Timing pattern,
 * not context propagation, and is out of scope here.)
 *
 * @api
 */
final readonly class TraceContextPropagator
{
    private const string TRACEPARENT = 'traceparent';
    private const string TRACESTATE = 'tracestate';
    private const string VERSION = '00';
    private const string VERSION_PATTERN = '/^[0-9a-f]{2}$/';
    private const string TRACE_ID_PATTERN = '/^[0-9a-f]{32}$/';
    private const string SPAN_ID_PATTERN = '/^[0-9a-f]{16}$/';
    private const string FLAGS_PATTERN = '/^[0-9a-f]{2}$/';
    private const string INVALID_VERSION = 'ff';

    public function extract(ServerRequestInterface $request): TraceContext
    {
        $context = $this->parse($request->getHeaderLine(self::TRACEPARENT));

        if (!$context->isValid()) {
            return $context;
        }

        return new TraceContext(
            traceId: $context->traceId,
            spanId: $context->spanId,
            traceFlags: $context->traceFlags,
            traceState: $request->getHeaderLine(self::TRACESTATE),
        );
    }

    public function inject(TraceContext $context, RequestInterface $request): RequestInterface
    {
        if (!$context->isValid()) {
            return $request;
        }

        $request = $request->withHeader(
            self::TRACEPARENT,
            \sprintf('%s-%s-%s-%02x', self::VERSION, $context->traceId, $context->spanId, $context->traceFlags),
        );

        if ($context->traceState !== '') {
            $request = $request->withHeader(self::TRACESTATE, $context->traceState);
        }

        return $request;
    }

    private function parse(string $header): TraceContext
    {
        $parts = explode('-', $header);

        if (\count($parts) !== 4) {
            return TraceContext::invalid();
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if (
            preg_match(self::VERSION_PATTERN, $version) !== 1
            || $version === self::INVALID_VERSION
            || preg_match(self::TRACE_ID_PATTERN, $traceId) !== 1
            || preg_match(self::SPAN_ID_PATTERN, $spanId) !== 1
            || preg_match(self::FLAGS_PATTERN, $flags) !== 1
        ) {
            return TraceContext::invalid();
        }

        $context = new TraceContext($traceId, $spanId, (int) hexdec($flags));

        return $context->isValid() ? $context : TraceContext::invalid();
    }
}
