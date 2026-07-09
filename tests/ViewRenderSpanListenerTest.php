<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Telemetry\Tests;

use Rasuvaeff\Yii3Telemetry\Tests\Support\RecordingTracer;
use Rasuvaeff\Yii3Telemetry\ViewRenderSpanListener;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\View\Event\View\AfterRender;
use Yiisoft\View\Event\View\BeforeRender;
use Yiisoft\View\View;

#[Test]
#[Covers(ViewRenderSpanListener::class)]
final class ViewRenderSpanListenerTest
{
    private RecordingTracer $tracer;
    private ViewRenderSpanListener $listener;
    private View $view;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->tracer = new RecordingTracer();
        $this->listener = new ViewRenderSpanListener($this->tracer);
        $this->view = new View();
    }

    public function tracesRenderWithViewName(): void
    {
        $this->listener->beforeRender(new BeforeRender($this->view, '/views/index.php', ['a' => 1]));
        $this->listener->afterRender(new AfterRender($this->view, '/views/index.php', ['a' => 1], '<html>'));

        Assert::count($this->tracer->spans, 1);

        $span = $this->tracer->spans[0];
        Assert::same($span->getName(), 'view.render');
        Assert::same($span->getAttributes()['view.name'], '/views/index.php');
        Assert::same($span->getAttributes()['view.result_length'], 6); // strlen('<html>')
        Assert::true($span->hasEnded());
    }

    public function nestedRendersEndInLifoOrder(): void
    {
        $this->listener->beforeRender(new BeforeRender($this->view, 'layout.php', []));
        $this->listener->beforeRender(new BeforeRender($this->view, 'partial.php', []));

        $this->listener->afterRender(new AfterRender($this->view, 'partial.php', [], 'p'));

        // The first afterRender ends the inner (partial) span, not the outer one.
        Assert::true($this->tracer->spans[1]->hasEnded());
        Assert::false($this->tracer->spans[0]->hasEnded());

        $this->listener->afterRender(new AfterRender($this->view, 'layout.php', [], 'l'));

        Assert::true($this->tracer->spans[0]->hasEnded());
        Assert::same($this->tracer->spans[0]->getAttributes()['view.name'], 'layout.php');
        Assert::same($this->tracer->spans[1]->getAttributes()['view.name'], 'partial.php');
    }

    public function afterRenderWithoutBeforeIsSafe(): void
    {
        $this->listener->afterRender(new AfterRender($this->view, 'x.php', [], 'r'));

        Assert::count($this->tracer->spans, 0);
    }
}
