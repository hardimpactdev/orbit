<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal s3 runtime probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $path = getenv('PATH');
        putenv('ORBIT_S3_RUNTIME_ORIGINAL_PATH='.($path === false ? '' : $path));
    });

    afterEach(function (): void {
        $path = getenv('ORBIT_S3_RUNTIME_ORIGINAL_PATH');
        putenv('PATH='.($path === false ? '' : $path));
        putenv('ORBIT_S3_RUNTIME_ORIGINAL_PATH');

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-s3-runtime-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_s3_runtime_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before touching docker', function (): void {
        Artisan::call('internal:s3-runtime:probe', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('probes the SeaweedFS runtime container through fixed docker argv', function (): void {
        $bin = install_s3_runtime_fake_bin([
            'exists' => true,
            'running' => true,
            'published_address' => '10.6.0.20:8333',
        ]);

        $exitCode = Artisan::call('internal:s3-runtime:probe', [
            '--operation-token' => s3_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(s3_runtime_success_data(Artisan::output()))
            ->toMatchArray([
                'exists' => '1',
                'running' => 'true',
                'published_address' => '10.6.0.20:8333',
                'stdout' => "exists=1\nrunning=true\npublished_address=10.6.0.20:8333\n",
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker container inspect orbit-seaweedfs')
            ->toContain('docker container inspect --format {{.State.Running}} orbit-seaweedfs')
            ->toContain(
                'docker container inspect --format {{range $p, $bindings := .NetworkSettings.Ports}}{{if eq $p "8333/tcp"}}{{range $bindings}}{{printf "%s:%s\n" .HostIp .HostPort}}{{end}}{{end}}{{end}} orbit-seaweedfs',
            );
    });

    it('returns absent state when the SeaweedFS runtime container is missing', function (): void {
        install_s3_runtime_fake_bin([
            'exists' => false,
        ]);

        $exitCode = Artisan::call('internal:s3-runtime:probe', [
            '--operation-token' => s3_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(s3_runtime_success_data(Artisan::output()))
            ->toMatchArray([
                'exists' => '0',
                'running' => 'false',
                'published_address' => '',
                'stdout' => "exists=0\nrunning=false\npublished_address=\n",
            ]);
    });
});

function s3_runtime_signed_operation_token(
    string $id = 's3-runtime-probe',
    string $node = 's3',
    string $command = 'internal:s3-runtime:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: s3_runtime_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function s3_runtime_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function s3_runtime_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

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

/**
 * @param  array{exists?: bool, running?: bool, published_address?: string}  $options
 */
function install_s3_runtime_fake_bin(array $options = []): string
{
    $dir = sys_get_temp_dir().'/orbit-s3-runtime-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    file_put_contents("{$dir}/exists", $options['exists'] ?? true ? '1' : '0');
    file_put_contents("{$dir}/running", $options['running'] ?? false ? 'true' : 'false');
    file_put_contents("{$dir}/published-address", $options['published_address'] ?? '');

    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename($argv[0]).' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        $args = array_slice($argv, 1);
        if ($args === ['container', 'inspect', 'orbit-seaweedfs']) {
            exit(trim(file_get_contents(__DIR__.'/exists')) === '1' ? 0 : 1);
        }
        if ($args === ['container', 'inspect', '--format', '{{.State.Running}}', 'orbit-seaweedfs']) {
            echo file_get_contents(__DIR__.'/running');
            exit(0);
        }
        if (($args[0] ?? null) === 'container' && ($args[1] ?? null) === 'inspect' && ($args[2] ?? null) === '--format') {
            echo file_get_contents(__DIR__.'/published-address');
            exit(0);
        }
        exit(1);
        PHP);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_s3_runtime_fake_bin(string $path): void
{
    foreach (['docker', 'calls.log', 'exists', 'running', 'published-address'] as $file) {
        $filePath = "{$path}/{$file}";

        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}
