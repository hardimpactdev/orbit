<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
    ])
    ->withPhpSets(php82: true)
    ->withTypeCoverageLevel(0)
    ->withCache(__DIR__.'/var/cache/rector')
    ->withoutParallel();
