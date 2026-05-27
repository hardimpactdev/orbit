<?php

declare(strict_types=1);

it('defines a gateway composer boundary before the app is moved', function (): void {
    $path = base_path('apps/gateway/composer.json');

    expect($path)->toBeFile();

    $composer = json_decode(
        (string) file_get_contents($path),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer)
        ->name->toBe('hardimpactdev/orbit-gateway')
        ->type->toBe('project')
        ->require->toMatchArray([
            'php' => '^8.5',
            'hardimpactdev/orbit-core' => 'dev-main',
            'laravel/framework' => '^13.0',
        ])
        ->and($composer['require'])->not->toHaveKey('laravel-zero/framework')
        ->and($composer['repositories'][0])->toMatchArray([
            'type' => 'path',
            'url' => '../../packages/core',
            'options' => [
                'symlink' => true,
            ],
        ])
        ->and($composer['autoload']['psr-4'])->toMatchArray([
            'App\\' => 'app/',
            'Database\\Factories\\' => 'database/factories/',
            'Database\\Seeders\\' => 'database/seeders/',
        ])
        ->and($composer['autoload-dev']['psr-4'])->toMatchArray([
            'Tests\\' => 'tests/',
        ]);
});

it('keeps the gateway script surface ready for root forwarding shims', function (): void {
    $composer = json_decode(
        (string) file_get_contents(base_path('apps/gateway/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(array_keys($composer['scripts']))
        ->toContain(
            'docs-lint',
            'test',
            'test:slow',
            'test:e2e',
            'test:e2e:docker',
            'test:e2e:docker:canary',
            'test:e2e:incus',
            'test:e2e:provision',
            'analyse',
            'format',
            'rector',
            'quality-check',
            'quality-check:fix',
        );
});
