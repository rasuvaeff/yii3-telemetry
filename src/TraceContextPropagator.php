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
 * - {@see toHeaders()} / {@see fromHeaders()} are the carrier-agnostic pair for
 *   non-HTTP transports — put the returned map into a queue message envelope,
 *   an AMQP header table, a gRPC metadata map — and restore the context on the
 *   consumer side. Header names are matched case-insensitively.
 *
 * Per W3C, a `traceparent` with an unknown future version is still parsed from
 * its first four fields; only version `00` requires the exact four-field form,
 * and version `ff` is always invalid.
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
        return $this->build(
            $request->getHeaderLine(self::TRACEPARENT),
            $request->getHeaderLine(self::TRACESTATE),
        );
    }

    public function inject(TraceContext $context, RequestInterface $request): RequestInterface
    {
        foreach ($this->toHeaders($context) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * Carrier-agnostic form of {@see inject()}: the W3C headers as a plain map
     * (`traceparent` + optional `tracestate`), empty for an invalid context.
     * Merge it into any transport envelope (queue message, AMQP headers, gRPC
     * metadata).
     *
     * @return array<string, string>
     */
    public function toHeaders(TraceContext $context): array
    {
        if (!$context->isValid()) {
            return [];
        }

        $headers = [
            self::TRACEPARENT => \sprintf('%s-%s-%s-%02x', self::VERSION, $context->traceId, $context->spanId, $context->traceFlags),
        ];

        if ($context->traceState !== '') {
            $headers[self::TRACESTATE] = $context->traceState;
        }

        return $headers;
    }

    /**
     * Carrier-agnostic form of {@see extract()}: reads the W3C headers from a
     * plain map. Keys are matched case-insensitively; a list value (PSR-7-style
     * multi-header) is joined with commas, which per W3C renders a duplicated
     * `traceparent` invalid.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function fromHeaders(array $headers): TraceContext
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = \is_array($value) ? implode(',', $value) : $value;
        }

        return $this->build(
            $normalized[self::TRACEPARENT] ?? '',
            $normalized[self::TRACESTATE] ?? '',
        );
    }

    private function build(string $traceparent, string $tracestate): TraceContext
    {
        $context = $this->parse($traceparent);

        if (!$context->isValid() || $tracestate === '') {
            return $context;
        }

        return new TraceContext(
            traceId: $context->traceId,
            spanId: $context->spanId,
            traceFlags: $context->traceFlags,
            traceState: $tracestate,
        );
    }

    private function parse(string $header): TraceContext
    {
        $parts = explode('-', $header);

        if (\count($parts) < 4) {
            return TraceContext::invalid();
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if (
            preg_match(self::VERSION_PATTERN, $version) !== 1
            || $version === self::INVALID_VERSION
            || ($version === self::VERSION && \count($parts) !== 4)
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
