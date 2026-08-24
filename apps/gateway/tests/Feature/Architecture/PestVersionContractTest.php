<?php

declare(strict_types=1);

/**
 * Monorepo Pest version contract: all seven active composer projects on Pest 5.
 *
 * The former apps/cli Pest 4 exception ended with Laravel Zero 13, which allows
 * Symfony Process ^7.4.13|^8.1 and therefore resolves Pest 5 without conflict.
 */
it('keeps all seven active composer projects on pest 5', function (): void {
    $pestFiveProjects = [
        'apps/gateway',
        'apps/docs',
        'apps/e2e',
        'apps/cli',
        'packages/core',
        'packages/sdk',
        'apps/ui',
    ];

    foreach ($pestFiveProjects as $projectPath) {
        $composer = json_decode(
            (string) file_get_contents(repo_path("{$projectPath}/composer.json")),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $pestConstraint = $composer['require-dev']['pestphp/pest'] ?? null;

        expect($pestConstraint)
            ->toBeString()
            ->toStartWith('^5.');

        if (array_key_exists('pestphp/pest-plugin-laravel', $composer['require-dev'] ?? [])) {
            expect($composer['require-dev']['pestphp/pest-plugin-laravel'])
                ->toBeString()
                ->toStartWith('^5.');
        }

        if (array_key_exists('phpunit/phpunit', $composer['require-dev'] ?? [])) {
            expect($composer['require-dev']['phpunit/phpunit'])
                ->toBeString()
                ->toStartWith('^13.');
        }
    }

    $cli = json_decode(
        (string) file_get_contents(repo_path('apps/cli/composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($cli['require']['laravel-zero/framework'] ?? null)
        ->toBeString()
        ->toStartWith('^13.');
});
