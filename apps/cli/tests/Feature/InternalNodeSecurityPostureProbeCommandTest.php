<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal node security posture probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_NODE_SECURITY_POSTURE_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.(string) ($_ENV['ORBIT_NODE_SECURITY_POSTURE_ORIGINAL_PATH'] ?? ''));

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-node-security-posture-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_node_security_posture_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before probing node posture', function (): void {
        Artisan::call('internal:node-security-posture:probe', [
            'managedUser' => 'orbit',
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects blank managed users after token validation', function (): void {
        Artisan::call('internal:node-security-posture:probe', [
            'managedUser' => ' ',
            '--operation-token' => node_security_posture_signed_operation_token(),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Managed user is required.',
                ['field' => 'managedUser'],
            ));
    });

    it('probes node posture through fixed argv operations', function (): void {
        $bin = install_node_security_posture_fake_id(exitCode: 0);

        $exitCode = Artisan::call('internal:node-security-posture:probe', [
            'managedUser' => 'orbit',
            '--operation-token' => node_security_posture_signed_operation_token(),
            '--json' => true,
        ]);

        $data = node_security_posture_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'runtime_user' => true,
                'sshd_listen' => true,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('id -u orbit');
    });
});

function node_security_posture_signed_operation_token(
    string $id = 'node-security-posture-probe',
    string $node = 'app-dev',
    string $command = 'internal:node-security-posture:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: node_security_posture_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function node_security_posture_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function node_security_posture_success_data(): array
{
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    $success = $payload['success'] ?? null;

    if (! is_array($success)) {
        return [];
    }

    $data = $success['data'] ?? null;

    if (! is_array($data)) {
        return [];
    }

    foreach (array_keys($data) as $key) {
        if (! is_string($key)) {
            return [];
        }
    }

    /** @var array<string, mixed> $data */
    return $data;
}

function install_node_security_posture_fake_id(int $exitCode): string
{
    $dir = sys_get_temp_dir().'/orbit-node-security-posture-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/id", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        exit({$exitCode});
        PHP);
    chmod(filename: "{$dir}/id", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_node_security_posture_fake_bin(string $path): void
{
    delete_node_security_posture_file("{$path}/id");
    delete_node_security_posture_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_node_security_posture_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
