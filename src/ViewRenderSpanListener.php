<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry;

use Yiisoft\View\Event\View\AfterRender;
use Yiisoft\View\Event\View\BeforeRender;

/**
 * PSR-14 listener that opens a span for each `yiisoft/view` render, bracketing
 * the `BeforeRender` / `AfterRender` events. Register both methods:
 *
 * ```php
 * BeforeRender::class => [[ViewRenderSpanListener::class, 'beforeRender']],
 * AfterRender::class  => [[ViewRenderSpanListener::class, 'afterRender']],
 * ```
 *
 * Nested renders (a view rendering a sub-view) form a LIFO stack.
 *
 * @api
 */
final class ViewRenderSpanListener
{
    /** @var list<SpanInterface> */
    private array $spans = [];

    public function __construct(
        private readonly TracerInterface $tracer,
    ) {}

    public function beforeRender(BeforeRender $event): void
    {
        $this->spans[] = $this->tracer->startSpan(
            'view.render',
            ['view.name' => $event->getFile()],
            TraceKind::Internal,
        );
    }

    public function afterRender(AfterRender $event): void
    {
        $span = array_pop($this->spans);

        if ($span === null) {
            return;
        }

        $span->setAttribute('view.result_length', \strlen($event->getResult()));
        $span->end();
    }
}
