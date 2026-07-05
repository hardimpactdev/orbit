<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => true,
    ]));
    $originalPath = getenv('PATH');
    $this->originalPath = $originalPath === false ? '' : $originalPath;
});

afterEach(function (): void {
    putenv("PATH={$this->originalPath}");

    $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-app-security-bin-*');

    if ($fakeBinPaths !== false) {
        foreach ($fakeBinPaths as $dir) {
            delete_app_security_fake_bin($dir);
        }
    }

    $fakeAppPaths = glob(sys_get_temp_dir().'/orbit-app-security-path-*');

    if ($fakeAppPaths !== false) {
        foreach ($fakeAppPaths as $dir) {
            delete_app_security_fake_path($dir);
        }
    }
});

describe('internal app security repair validation', function (): void {
    it('rejects a missing operation token before repairing security', function (): void {
        [$exitCode, $output] = run_internal_app_security_repair_command([
            'user' => 'docs',
            'home' => '/home/docs',
            'path' => '/home/orbit/apps/docs',
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

    it('rejects invalid runtime users after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_security_repair_command([
            'user' => 'bad/user',
            'home' => '/home/docs',
            'path' => '/home/orbit/apps/docs',
            '--operation-token' => app_security_repair_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Runtime user is invalid.',
                ['field' => 'user'],
            ));
    });
});

describe('internal app security repair execution', function (): void {
    it('creates missing users and repairs existing app path permissions', function (): void {
        $appPath = app_security_fake_path();
        install_app_security_fake_bin(idExit: 1);

        [$exitCode, $output] = run_internal_app_security_repair_command([
            'user' => 'docs',
            'home' => '/home/docs',
            'path' => $appPath,
            '--operation-token' => app_security_repair_signed_operation_token(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['user'])
            ->toBe('docs')
            ->and($decoded['success']['data']['home'])
            ->toBe('/home/docs')
            ->and($decoded['success']['data']['path'])
            ->toBe($appPath)
            ->and(array_column($decoded['success']['data']['commands'], 'command'))
            ->toContain([
                'sudo',
                'useradd',
                '--system',
                '--create-home',
                '--home-dir',
                '/home/docs',
                '--shell',
                '/usr/sbin/nologin',
                'docs',
            ])
            ->toContain(['sudo', 'install', '-d', '-m', '0750', '-o', 'docs', '-g', 'docs', '/home/docs'])
            ->toContain(['sudo', 'chown', '-R', 'docs:docs', $appPath])
            ->toContain(['sudo', 'chmod', '-R', 'go-w', $appPath]);
    });

    it('does not create an existing user or chown a missing app path', function (): void {
        install_app_security_fake_bin(idExit: 0);

        [$exitCode, $output] = run_internal_app_security_repair_command([
            'user' => 'docs',
            'home' => '/home/docs',
            'path' => '/tmp/orbit-app-security-path-missing',
            '--operation-token' => app_security_repair_signed_operation_token(),
            '--json' => true,
        ]);

        $commands = array_column(
            json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['success']['data']['commands'],
            'command',
        );

        expect($exitCode)
            ->toBe(0)
            ->and($commands)
            ->not
            ->toContain([
                'sudo',
                'useradd',
                '--system',
                '--create-home',
                '--home-dir',
                '/home/docs',
                '--shell',
                '/usr/sbin/nologin',
                'docs',
            ])
            ->and($commands)
            ->toBe([
                ['sudo', 'install', '-d', '-m', '0750', '-o', 'docs', '-g', 'docs', '/home/docs'],
            ]);
    });
});

function app_security_repair_signed_operation_token(
    string $id = 'app-security-repair',
    string $node = 'app-dev',
    string $command = 'internal:app-security:repair',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: app_security_repair_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function app_security_repair_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_app_security_repair_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-security:repair']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function app_security_fake_path(): string
{
    $path = sys_get_temp_dir().'/orbit-app-security-path-'.bin2hex(random_bytes(8));
    mkdir($path);

    return $path;
}

function install_app_security_fake_bin(int $idExit): void
{
    $dir = sys_get_temp_dir().'/orbit-app-security-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/id", <<<PHP
        #!/usr/bin/env php
        <?php
        exit({$idExit});
        PHP);
    chmod(filename: "{$dir}/id", permissions: 0o755);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));
}

function delete_app_security_fake_bin(string $path): void
{
    delete_app_security_file("{$path}/id");
    delete_app_security_file("{$path}/sudo");
    delete_app_security_directory($path);
}

function delete_app_security_fake_path(string $path): void
{
    delete_app_security_directory($path);
}

function delete_app_security_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

function delete_app_security_directory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    rmdir($path);
}
