<?php

declare(strict_types=1);

it('keeps Rector caches isolated per project and per parallel worker', function (): void {
    $repository = dirname(__DIR__, 5);
    $projects = [
        'apps/cli',
        'apps/docs',
        'apps/gateway',
        'apps/e2e',
        'packages/php-sdk',
    ];

    foreach ($projects as $project) {
        $configuration = file_get_contents($repository.'/'.$project.'/rector.php');

        expect($configuration)
            ->not->toBeFalse()
            ->toContain(<<<'PHP'
                ->withCache(
                        (require __DIR__.'/../../tools/rector-cache.php')(__DIR__),
                        null,
                        __DIR__.'/vendor/rector',
                    )
                    ->withParallel(maxNumberOfProcess: require __DIR__.'/../../tools/check-processes.php')
                PHP);
    }
});

it('gives the main Rector process the project cache and each worker a private one', function (): void {
    $repository = dirname(__DIR__, 5);
    $original = $_SERVER['argv'] ?? null;

    try {
        $_SERVER['argv'] = ['vendor/bin/rector', 'process', '--dry-run'];
        expect((require $repository.'/tools/rector-cache.php')('/project'))->toBe('/project/vendor/rector/cache');

        $_SERVER['argv'] = ['vendor/bin/rector', 'worker', '--port', '1234'];
        $first = (require $repository.'/tools/rector-cache.php')('/project');
        $second = (require $repository.'/tools/rector-cache.php')('/project');

        expect($first)->toStartWith('/project/vendor/rector/cache/workers/')
            ->and($second)->toStartWith('/project/vendor/rector/cache/workers/')
            ->and($first)->not->toBe($second);
    } finally {
        $_SERVER['argv'] = $original;
    }
});

it('reads the allocated lane share for PHPStan and Rector', function (): void {
    $repository = dirname(__DIR__, 5);
    $original = getenv('ORBIT_CHECK_PROCESSES');

    try {
        putenv('ORBIT_CHECK_PROCESSES');
        $default = (int) trim((string) shell_exec(escapeshellarg($repository.'/bin/check-processes')));
        expect(require $repository.'/tools/check-processes.php')->toBe($default)
            ->and(require $repository.'/tools/phpstan/parallel.php')
            ->toBe(['parameters' => ['parallel' => ['maximumNumberOfProcesses' => $default]]]);

        putenv('ORBIT_CHECK_PROCESSES=12');
        expect(require $repository.'/tools/check-processes.php')->toBe(12);

        putenv('ORBIT_CHECK_PROCESSES=3');
        expect(require $repository.'/tools/check-processes.php')->toBe(3)
            ->and(require $repository.'/tools/phpstan/parallel.php')
            ->toBe(['parameters' => ['parallel' => ['maximumNumberOfProcesses' => 3]]]);

        putenv('ORBIT_CHECK_PROCESSES=0');
        expect(fn (): mixed => require $repository.'/tools/check-processes.php')
            ->toThrow(RuntimeException::class, 'ORBIT_CHECK_PROCESSES must be a positive integer');
    } finally {
        putenv($original === false ? 'ORBIT_CHECK_PROCESSES' : 'ORBIT_CHECK_PROCESSES='.$original);
    }
});
