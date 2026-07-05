<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal runtime backend probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        putenv('ORBIT_RUNTIME_BACKEND_ORIGINAL_PATH='.($originalPath === false ? '' : $originalPath));
    });

    afterEach(function (): void {
        $originalPath = getenv('ORBIT_RUNTIME_BACKEND_ORIGINAL_PATH');
        putenv('PATH='.($originalPath === false ? '' : $originalPath));
        putenv('ORBIT_RUNTIME_BACKEND_ORIGINAL_PATH');

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-runtime-backend-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_runtime_backend_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before probing runtime binaries', function (): void {
        Artisan::call('internal:runtime-backend:probe', [
            'provider' => 'systemd',
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid providers after token validation', function (): void {
        Artisan::call('internal:runtime-backend:probe', [
            'provider' => 'bash',
            '--operation-token' => runtime_backend_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Runtime backend provider is invalid.',
                ['field' => 'provider'],
            ));
    });

    it('probes systemd through fixed argv', function (): void {
        $bin = install_runtime_backend_fake_bin('systemctl', output: "systemd 255\n");

        $exitCode = Artisan::call('internal:runtime-backend:probe', [
            'provider' => 'systemd',
            '--operation-token' => runtime_backend_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(runtime_backend_probe_success_data())
            ->toMatchArray([
                'provider' => 'systemd',
                'available' => true,
                'exit_code' => 0,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('systemctl --version');
    });

    it('reports unavailable docker backends without running arbitrary shell', function (): void {
        install_runtime_backend_fake_bin('docker', output: "daemon unavailable\n", exitCode: 1);

        $exitCode = Artisan::call('internal:runtime-backend:probe', [
            'provider' => 'docker',
            '--operation-token' => runtime_backend_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(runtime_backend_probe_success_data())
            ->toMatchArray([
                'provider' => 'docker',
                'available' => false,
                'exit_code' => 1,
                'output' => 'daemon unavailable',
            ]);
    });

    it('probes gateway runtime container state through fixed docker argv', function (): void {
        $bin = install_runtime_backend_fake_docker([
            'docker info' => [0, "ok\n"],
            'docker container inspect --format {{.State.Running}} orbit-gateway' => [0, "true\n"],
        ]);

        $exitCode = Artisan::call('internal:gateway-runtime-backend:probe', [
            'container' => 'orbit-gateway',
            '--operation-token' => runtime_backend_probe_signed_operation_token(
                command: 'internal:gateway-runtime-backend:probe',
            ),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(runtime_backend_probe_success_data())
            ->toMatchArray([
                'runtime_status' => 'available',
                'container_exists' => true,
                'container_running' => true,
                'exit_code' => 0,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker info')
            ->toContain('docker container inspect --format {{.State.Running}} orbit-gateway');
    });
});

function runtime_backend_probe_signed_operation_token(
    string $id = 'runtime-backend-probe',
    string $node = 'app-dev',
    string $command = 'internal:runtime-backend:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: runtime_backend_probe_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function runtime_backend_probe_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function runtime_backend_probe_success_data(): array
{
    /** @var mixed $payload */
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $data */
    $data = data_get(target: $payload, key: 'success.data');

    if (! is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $data */
    return $data;
}

function install_runtime_backend_fake_bin(string $binary, string $output, int $exitCode = 0): string
{
    $dir = sys_get_temp_dir().'/orbit-runtime-backend-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $outputPath = "{$dir}/output";
    file_put_contents($outputPath, $output);

    file_put_contents("{$dir}/{$binary}", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        echo file_get_contents('{$outputPath}');
        exit({$exitCode});
        PHP);
    chmod(filename: "{$dir}/{$binary}", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

/**
 * @param  array<string, array{int, string}>  $responses
 */
function install_runtime_backend_fake_docker(array $responses): string
{
    $dir = sys_get_temp_dir().'/orbit-runtime-backend-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    file_put_contents("{$dir}/responses.json", json_encode($responses, JSON_THROW_ON_ERROR));

    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        $command = basename($argv[0]).' '.implode(' ', array_slice($argv, 1));
        file_put_contents(__DIR__.'/calls.log', $command.PHP_EOL, FILE_APPEND);
        $responses = json_decode(file_get_contents(__DIR__.'/responses.json'), true);
        [$exitCode, $output] = $responses[$command] ?? [1, 'unexpected command'];
        echo $output;
        exit($exitCode);
        PHP);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_runtime_backend_fake_bin(string $path): void
{
    delete_runtime_backend_file("{$path}/docker");
    delete_runtime_backend_file("{$path}/systemctl");
    delete_runtime_backend_file("{$path}/calls.log");
    delete_runtime_backend_file("{$path}/output");
    delete_runtime_backend_file("{$path}/responses.json");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_runtime_backend_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
