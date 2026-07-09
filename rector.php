<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    // The test suite is reflection-driven by design: #[Property] generator
    // methods are invoked by name via reflection, so Rector's static dead-code
    // analysis cannot see those call sites and would strip them. Exempt the
    // dead-code rules that would break those fixtures.
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class => [__DIR__ . '/tests'],
        RemoveEmptyClassMethodRector::class => [__DIR__ . '/tests'],
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/tests'],
    ]);
