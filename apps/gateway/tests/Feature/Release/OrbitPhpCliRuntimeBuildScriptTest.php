<?php

declare(strict_types=1);

it('builds Orbit host PHP with a pinned fixed SQLite source and verifies both version surfaces', function (): void {
    $scriptPath = repo_path('bin/orbit-build-php-cli-runtime');

    expect($scriptPath)->toBeFile();

    $script = file_get_contents($scriptPath);

    expect($script)
        ->toContain('STATIC_PHP_CLI_VERSION=2.8.5')
        ->toContain('SQLITE_VERSION=3.44.6')
        ->toContain('8.5.8)')
        ->not->toContain('8.4.23)')
        ->not->toContain('8.3.32)')->toContain('linux-x86_64)')->toContain('macos-aarch64)')
        ->not->toContain('linux-aarch64)')
        ->not->toContain('macos-x86_64)')->toContain(
            '863c171b76cd36e0c71662d4a50d84da531cc7fbead893d02459577febdf6396',
        )->toContain('c25cd42f803d5fb0af5f1a2863c9f529a8fd35177cb24b0f6e970b1cc96f00f0')->toContain(
            '--custom-url',
        )->toContain('PKG_CONFIG_LIBDIR=')->toContain('PKG_CONFIG_PATH=')->toContain('./spc doctor')->toContain(
            '--auto-fix',
        )->toContain('SQLite3::version()')->toContain('new PDO("sqlite::memory:")')->toContain(
            'select sqlite_version()',
        )->toContain('shasum -a 256');
});
