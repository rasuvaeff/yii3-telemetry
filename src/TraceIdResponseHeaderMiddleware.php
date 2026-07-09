<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Telemetry\Exception\InvalidArgumentException;

/**
 * Opt-in PSR-15 middleware exposing the current trace id to the client as a
 * response header (default `X-Trace-Id`) — so an error page / API consumer can
 * quote the exact trace to look up in the tracing backend.
 *
 * Place it AFTER the tracing middleware in the stack (inside the root span), so
 * the context is valid when the response passes through. Without an active
 * valid context the response is returned untouched.
 *
 * This deliberately does NOT emit a W3C `traceresponse` header — that draft
 * carries different semantics; a plain custom header is the support-ticket
 * pattern.
 *
 * @api
 */
final readonly class TraceIdResponseHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TracerInterface $tracer,
        private string $headerName = 'X-Trace-Id',
    ) {
        if ($this->headerName === '') {
            throw new InvalidArgumentException('Header name must not be empty');
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $context = $this->tracer->getContext();

        if (!$context->isValid()) {
            return $response;
        }

        return $response->withHeader($this->headerName, $context->traceId);
    }
}
