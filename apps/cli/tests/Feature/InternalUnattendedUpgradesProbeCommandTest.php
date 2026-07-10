<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal unattended upgrades probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_UNATTENDED_UPGRADES_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.($_ENV['ORBIT_UNATTENDED_UPGRADES_ORIGINAL_PATH'] ?? ''));

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-unattended-upgrades-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_unattended_upgrades_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before probing local state', function (): void {
        Artisan::call('internal:unattended-upgrades:probe', [
            'autoHash' => str_repeat('a', times: 64),
            'unattendedHash' => str_repeat('b', times: 64),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid expected hashes after token validation', function (): void {
        Artisan::call('internal:unattended-upgrades:probe', [
            'autoHash' => 'not-a-hash',
            'unattendedHash' => str_repeat('b', times: 64),
            '--operation-token' => unattended_upgrades_signed_operation_token(),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Unattended-upgrades expected config hash is invalid.',
                ['field' => 'autoHash'],
            ));
    });

    it('probes package state through fixed dpkg argv', function (): void {
        $bin = install_unattended_upgrades_fake_dpkg(output: 'install ok installed');

        $exitCode = Artisan::call('internal:unattended-upgrades:probe', [
            'autoHash' => str_repeat('a', times: 64),
            'unattendedHash' => str_repeat('b', times: 64),
            '--operation-token' => unattended_upgrades_signed_operation_token(),
            '--json' => true,
        ]);
        $data = unattended_upgrades_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'installed' => true,
                'dry_run_exit' => null,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('dpkg-query -W -f=${Status} unattended-upgrades');
    });

    it('applies unattended upgrades through fixed sudo argv', function (): void {
        $bin = install_unattended_upgrades_fake_sudo();

        $exitCode = Artisan::call('internal:unattended-upgrades:apply', [
            '--operation-token' => unattended_upgrades_signed_operation_token(
                command: 'internal:unattended-upgrades:apply',
            ),
            '--json' => true,
        ]);
        $data = unattended_upgrades_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'exit_code' => 0,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo unattended-upgrade');
    });
});

function unattended_upgrades_signed_operation_token(
    string $id = 'unattended-upgrades-probe',
    string $node = 'app-dev',
    string $command = 'internal:unattended-upgrades:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: unattended_upgrades_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function unattended_upgrades_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function unattended_upgrades_success_data(): array
{
    /** @var mixed $payload */
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $success */
    $success = $payload['success'] ?? null;

    if (! is_array($success)) {
        return [];
    }

    /** @var mixed $data */
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

function install_unattended_upgrades_fake_dpkg(string $output, int $exitCode = 0): string
{
    $dir = sys_get_temp_dir().'/orbit-unattended-upgrades-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $outputPath = "{$dir}/output";
    file_put_contents($outputPath, $output);

    file_put_contents("{$dir}/dpkg-query", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        echo file_get_contents('{$outputPath}');
        exit({$exitCode});
        PHP);
    chmod(filename: "{$dir}/dpkg-query", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function install_unattended_upgrades_fake_sudo(int $exitCode = 0): string
{
    $dir = sys_get_temp_dir().'/orbit-unattended-upgrades-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        exit({$exitCode});
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_unattended_upgrades_fake_bin(string $path): void
{
    delete_unattended_upgrades_file("{$path}/dpkg-query");
    delete_unattended_upgrades_file("{$path}/sudo");
    delete_unattended_upgrades_file("{$path}/calls.log");
    delete_unattended_upgrades_file("{$path}/output");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_unattended_upgrades_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
