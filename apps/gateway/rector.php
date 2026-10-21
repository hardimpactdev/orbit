<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;

return RectorConfig::configure()
    ->withCache(
        (require __DIR__.'/../../tools/rector-cache.php')(__DIR__),
        null,
        __DIR__.'/vendor/rector',
    )
    ->withParallel(maxNumberOfProcess: require __DIR__.'/../../tools/check-processes.php')
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/extensions',
    ])
    ->withSkip([
        // Constructor promotion loses the default when an older queued payload is unserialized.
        ClassPropertyAssignToConstructorPromotionRector::class => [__DIR__.'/app/Jobs/RunScheduledCommand.php'],
    ])
    ->withPhpSets();
