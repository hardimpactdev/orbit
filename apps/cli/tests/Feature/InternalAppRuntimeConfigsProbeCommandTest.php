<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app runtime configs probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $home = getenv('HOME');
        $this->originalHome = is_string($home) ? $home : null;
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $this->originalEnvHome = $_ENV['HOME'] ?? null;
    });

    afterEach(function (): void {
        app_runtime_configs_probe_restore_home(
            $this->originalHome,
            $this->originalServerHome,
            $this->originalEnvHome,
        );

        $homeDirectories = glob(sys_get_temp_dir().'/orbit-runtime-configs-home-*');

        foreach ($homeDirectories === false ? [] : $homeDirectories as $dir) {
            delete_runtime_configs_probe_home($dir);
        }
    });

    it('rejects a missing operation token before probing runtime configs', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_configs_probe_command([
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('reports proven-absent runtime config directory', function (): void {
        app_runtime_configs_probe_home();

        [$exitCode, $output] = run_internal_app_runtime_configs_probe_command([
            '--operation-token' => app_runtime_configs_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'status' => 'absent',
                'paths' => [],
                'error' => '',
                'stdout' => "orbit-config-dir:absent\n",
            ]));
    });

    it('reports present runtime config paths', function (): void {
        $home = app_runtime_configs_probe_home();
        $directory = "{$home}/.config/orbit/apps";
        mkdir($directory, recursive: true);
        file_put_contents("{$directory}/docs.ini", data: 'memory_limit=512M');
        file_put_contents("{$directory}/marketing.ini", data: 'memory_limit=256M');

        [$exitCode, $output] = run_internal_app_runtime_configs_probe_command([
            '--operation-token' => app_runtime_configs_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'status' => 'present',
                'paths' => [
                    "{$home}/.config/orbit/apps/docs.ini",
                    "{$home}/.config/orbit/apps/marketing.ini",
                ],
                'error' => '',
                'stdout' => "orbit-config-dir:present\n{$home}/.config/orbit/apps/docs.ini\n{$home}/.config/orbit/apps/marketing.ini\n",
            ]));
    });

    it('reports invalid runtime config paths as error sentinels', function (): void {
        $home = app_runtime_configs_probe_home();
        mkdir("{$home}/.config/orbit", recursive: true);
        file_put_contents("{$home}/.config/orbit/apps", data: 'not a directory');

        [$exitCode, $output] = run_internal_app_runtime_configs_probe_command([
            '--operation-token' => app_runtime_configs_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'status' => 'error',
                'paths' => [],
                'error' => "{$home}/.config/orbit/apps is not a directory",
                'stdout' => "orbit-config-dir:error {$home}/.config/orbit/apps is not a directory\n",
            ]));
    });
});

function app_runtime_configs_probe_signed_operation_token(
    string $id = 'app-runtime-configs-probe',
    string $node = 'app-dev',
    string $command = 'internal:app-runtime-configs:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_app_runtime_configs_probe_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-runtime-configs:probe']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function app_runtime_configs_probe_home(): string
{
    $home = sys_get_temp_dir().'/orbit-runtime-configs-home-'.bin2hex(random_bytes(8));
    mkdir($home);
    putenv("HOME={$home}");
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    return $home;
}

function app_runtime_configs_probe_restore_home(?string $home, ?string $serverHome, ?string $envHome): void
{
    $home === null ? putenv('HOME') : putenv("HOME={$home}");

    if ($serverHome === null) {
        unset($_SERVER['HOME']);
    }

    if ($serverHome !== null) {
        $_SERVER['HOME'] = $serverHome;
    }

    if ($envHome === null) {
        unset($_ENV['HOME']);
    }

    if ($envHome !== null) {
        $_ENV['HOME'] = $envHome;
    }
}

function delete_runtime_configs_probe_home(string $path): void
{
    if (! is_dir($path)) {
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }

        return;
    }

    $entries = scandir($path);

    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        delete_runtime_configs_probe_home("{$path}/{$entry}");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}
