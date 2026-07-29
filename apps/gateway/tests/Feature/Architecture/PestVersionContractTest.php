<?php

declare(strict_types=1);

/**
 * Monorepo Pest version contract: five projects on Pest 5, CLI retained on Pest 4.
 *
 * apps/cli stays on Pest 4 while Laravel Zero 12 hard-requires Symfony Process 7.x
 * and Pest 5 requires Symfony Process ^8.1. Remove the CLI exception when a stable
 * Laravel Zero (or successor stack) resolves Pest 5 without process 7/8 conflict.
 */
it('keeps five active composer projects on pest 5 and retains cli on pest 4', function (): void {
    $pestFiveProjects = [
        'apps/gateway',
        'apps/docs',
        'apps/e2e',
        'packages/core',
        'packages/sdk',
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

    expect($cli['require-dev']['pestphp/pest'] ?? null)
        ->toBeString()
        ->toStartWith('^4.')
        ->and($cli['require-dev']['pestphp/pest-plugin-laravel'] ?? null)
        ->toBeString()
        ->toStartWith('^4.')
        ->and($cli['require']['laravel-zero/framework'] ?? null)
        ->toBeString()
        ->toStartWith('^12.');
});
