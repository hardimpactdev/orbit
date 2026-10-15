<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withCache(
        (require __DIR__.'/../../tools/rector-cache.php')(__DIR__),
        null,
        __DIR__.'/vendor/rector',
    )
    ->withParallel(maxNumberOfProcess: require __DIR__.'/../../tools/check-processes.php')
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/tests',
    ])
    ->withPhpSets();
