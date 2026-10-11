<?php

declare(strict_types=1);

use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function loadAppConfigWith(array $environment): array
{
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /** @var array<string, mixed> $config */
    $config = require base_path('config/app.php');

    foreach ($previous as $name => $value) {
        if ($value === false) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    return $config;
}

function temporaryOrbitHome(): string
{
    $home = storage_path('framework/testing/orbit-home-'.Str::uuid());
    File::ensureDirectoryExists($home);

    return $home;
}

it('reads the Gateway encryption key from ORBIT_HOME when APP_KEY is unset', function (): void {
    $home = temporaryOrbitHome();
    $storedKey = 'base64:'.base64_encode(random_bytes(32));
    File::put("{$home}/gateway.app-key", "{$storedKey}\n");

    $config = loadAppConfigWith(['APP_KEY' => null, 'ORBIT_HOME' => $home]);

    expect($config['key'])->toBe($storedKey);

    File::deleteDirectory($home);
});

it('prefers an explicit APP_KEY over the stored Gateway key', function (): void {
    $home = temporaryOrbitHome();
    File::put("{$home}/gateway.app-key", "base64:stored\n");

    $config = loadAppConfigWith(['APP_KEY' => 'base64:explicit', 'ORBIT_HOME' => $home]);

    expect($config['key'])->toBe('base64:explicit');

    File::deleteDirectory($home);
});

it('leaves the key unset without APP_KEY or a stored Gateway key', function (): void {
    $home = temporaryOrbitHome();

    $config = loadAppConfigWith(['APP_KEY' => null, 'ORBIT_HOME' => $home]);

    expect($config['key'])->toBeNull();

    File::deleteDirectory($home);
});

it('writes one Gateway key to ORBIT_HOME and keeps it on a second run', function (): void {
    $home = temporaryOrbitHome().'/state';
    $script = dirname(base_path(), 2).'/bin/gateway-app-key';
    $run = static fn (): int => new NativeProcessRunner()->run(new ProcessInvocation(['bash', $script], timeout: 30.0, environment: ['ORBIT_HOME' => $home]))->exitCode;

    expect($run())->toBe(0);

    $key = (string) file_get_contents("{$home}/gateway.app-key");

    expect($key)->toMatch('/\Abase64:[A-Za-z0-9+\/]{43}=\n\z/')
        ->and(fileperms("{$home}/gateway.app-key") & 0777)->toBe(0600)
        ->and(fileperms($home) & 0777)->toBe(0700)
        ->and($run())->toBe(0)
        ->and(file_get_contents("{$home}/gateway.app-key"))->toBe($key);

    File::deleteDirectory(dirname($home));
});

it('sets up a development Gateway with the stored key instead of a key in .env', function (): void {
    /** @var array{scripts: array{setup: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['setup'])->toContain('../../bin/gateway-app-key')
        ->and(implode("\n", $composer['scripts']['setup']))->not->toContain('key:generate');
});
