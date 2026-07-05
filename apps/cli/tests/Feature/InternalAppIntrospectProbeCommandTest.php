<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app introspect probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $path = getenv('PATH');
        $this->originalPath = is_string($path) ? $path : '';
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");

        $paths = glob(sys_get_temp_dir().'/orbit-app-introspect-*');

        foreach (is_array($paths) ? $paths : [] as $path) {
            delete_app_introspect_fixture($path);
        }
    });

    it('rejects a missing operation token before probing app reality', function (): void {
        [$exitCode, $output] = run_internal_app_introspect_probe_command([
            '--json' => true,
        ], []);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('returns a typed app reality snapshot from a stdin payload', function (): void {
        $path = app_introspect_fixture();
        putenv('PATH='.app_introspect_fake_docker(exitCode: 127));

        [$exitCode, $output] = run_internal_app_introspect_probe_command([
            '--operation-token' => app_introspect_probe_signed_operation_token(),
            '--json' => true,
        ], [
            'name' => 'docs',
            'path' => $path,
            'document_root' => 'public',
            'runtime_kind' => 'static',
            'runtime_user' => '',
            'runtime_container_name' => '',
            'expected_spec_hash' => '',
            'runtime_config_path' => '',
            'expected_runtime_config_hash' => '',
            'expected_runtime_image' => '',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'snapshot' => [
                    'name' => 'docs',
                    'path_exists' => true,
                    'root_exists' => true,
                    'root_inside_path' => true,
                    'docker_available' => false,
                    'container_exists' => false,
                    'container_spec_matches' => false,
                    'container_running' => false,
                    'system_user_exists' => false,
                    'fs_permissions_ok' => false,
                    'runtime_config_exists' => false,
                    'runtime_config_matches' => true,
                    'runtime_image_available' => true,
                    'runtime_image_probe_failed' => false,
                ],
            ]));
    });
});

function app_introspect_probe_signed_operation_token(
    string $id = 'app-introspect-probe',
    string $node = 'app-dev',
    string $command = 'internal:app-introspect:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: app_introspect_probe_secret(),
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
 * @param  array<string, mixed>  $payload
 * @return array{int, string}
 */
function run_internal_app_introspect_probe_command(array $parameters, array $payload): array
{
    $input = new ArrayInput($parameters);
    $stream = fopen('php://memory', mode: 'r+');

    if ($stream === false) {
        throw new RuntimeException('Unable to open memory stream.');
    }

    fwrite($stream, json_encode($payload, JSON_THROW_ON_ERROR));
    rewind($stream);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-introspect:probe']->run($input, $output);

    fclose($stream);

    return [$exitCode, trim($output->fetch())];
}

function app_introspect_fixture(): string
{
    $path = sys_get_temp_dir().'/orbit-app-introspect-'.bin2hex(random_bytes(8));
    mkdir("{$path}/public", recursive: true);

    return $path;
}

function app_introspect_fake_docker(int $exitCode): string
{
    $path = sys_get_temp_dir().'/orbit-app-introspect-'.bin2hex(random_bytes(8));
    mkdir($path);
    file_put_contents("{$path}/docker", "#!/bin/sh\nexit {$exitCode}\n");
    chmod("{$path}/docker", permissions: 0o755);

    return $path;
}

function delete_app_introspect_fixture(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    foreach (is_array($entries) ? $entries : [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        delete_app_introspect_fixture("{$path}/{$entry}");
    }

    rmdir($path);
}

function app_introspect_probe_secret(): string
{
    return implode('', ['gateway', '-secret']);
}
