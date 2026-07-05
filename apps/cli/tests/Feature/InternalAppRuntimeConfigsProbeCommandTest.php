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
        $this->originalPath = getenv('PATH') ?: '';
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");

        foreach (glob(sys_get_temp_dir().'/orbit-runtime-configs-sudo-*') ?: [] as $dir) {
            delete_runtime_configs_fake_sudo($dir);
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
        install_runtime_configs_fake_sudo(testExit: 1);

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
        install_runtime_configs_fake_sudo(findOutput: "/etc/orbit/apps/docs.ini\n/etc/orbit/apps/blog.ini\n");

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
                    '/etc/orbit/apps/docs.ini',
                    '/etc/orbit/apps/blog.ini',
                ],
                'error' => '',
                'stdout' => "orbit-config-dir:present\n/etc/orbit/apps/docs.ini\n/etc/orbit/apps/blog.ini\n",
            ]));
    });

    it('reports find failures as error sentinels', function (): void {
        install_runtime_configs_fake_sudo(findExit: 2, findError: 'find: Permission denied');

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
                'error' => 'find: Permission denied',
                'stdout' => "orbit-config-dir:error find: Permission denied\n",
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

function install_runtime_configs_fake_sudo(
    int $testExit = 0,
    string $testError = '',
    int $findExit = 0,
    string $findOutput = '',
    string $findError = '',
): void {
    $dir = sys_get_temp_dir().'/orbit-runtime-configs-sudo-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $testError = var_export($testError, true);
    $findOutput = var_export($findOutput, true);
    $findError = var_export($findError, true);

    $script = <<<PHP
        #!/usr/bin/env php
        <?php

        \$args = array_slice(\$argv, 1);

        if (\$args === ['test', '-d', '/etc/orbit/apps']) {
            fwrite(STDERR, {$testError});
            exit({$testExit});
        }

        if (\$args === ['find', '/etc/orbit/apps', '-maxdepth', '1', '-type', 'f', '-name', '*.ini', '-print']) {
            fwrite(STDOUT, {$findOutput});
            fwrite(STDERR, {$findError});
            exit({$findExit});
        }

        fwrite(STDERR, 'unexpected sudo invocation: '.implode(' ', \$args));
        exit(99);
        PHP;

    file_put_contents("{$dir}/sudo", $script);
    chmod("{$dir}/sudo", 0o755);

    putenv("PATH={$dir}:".(getenv('PATH') ?: ''));
}

function delete_runtime_configs_fake_sudo(string $path): void
{
    @unlink("{$path}/sudo");
    @rmdir($path);
}
