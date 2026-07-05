<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app runtime containers probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $this->originalPath = getenv('PATH') ?: '';
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");

        foreach (glob(sys_get_temp_dir().'/orbit-runtime-containers-docker-*') ?: [] as $dir) {
            delete_runtime_containers_fake_docker($dir);
        }
    });

    it('rejects a missing operation token before probing runtime containers', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_containers_probe_command([
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

    it('reports absent when docker is unavailable', function (): void {
        install_runtime_containers_fake_docker(versionExit: 127);

        [$exitCode, $output] = run_internal_app_runtime_containers_probe_command([
            '--operation-token' => app_runtime_containers_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'status' => 'absent',
                'containers' => [],
                'error' => '',
                'stdout' => "orbit-container-scan:absent\n",
            ]));
    });

    it('reports present runtime containers', function (): void {
        install_runtime_containers_fake_docker(scanOutput: "orbit-app-docs\tdocs\norbit-app-blog\tblog\n");

        [$exitCode, $output] = run_internal_app_runtime_containers_probe_command([
            '--operation-token' => app_runtime_containers_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'status' => 'present',
                'containers' => [
                    [
                        'container_name' => 'orbit-app-docs',
                        'app_slug' => 'docs',
                    ],
                    [
                        'container_name' => 'orbit-app-blog',
                        'app_slug' => 'blog',
                    ],
                ],
                'error' => '',
                'stdout' => "orbit-container-scan:present\norbit-app-docs\tdocs\norbit-app-blog\tblog\n",
            ]));
    });

    it('reports docker scan failures as error sentinels', function (): void {
        install_runtime_containers_fake_docker(scanExit: 1, scanError: 'Cannot connect to the Docker daemon');

        [$exitCode, $output] = run_internal_app_runtime_containers_probe_command([
            '--operation-token' => app_runtime_containers_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'status' => 'error',
                'containers' => [],
                'error' => 'Cannot connect to the Docker daemon',
                'stdout' => "orbit-container-scan:error Cannot connect to the Docker daemon\n",
            ]));
    });
});

function app_runtime_containers_probe_signed_operation_token(
    string $id = 'app-runtime-containers-probe',
    string $node = 'app-dev',
    string $command = 'internal:app-runtime-containers:probe',
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
function run_internal_app_runtime_containers_probe_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-runtime-containers:probe']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function install_runtime_containers_fake_docker(
    int $versionExit = 0,
    int $scanExit = 0,
    string $scanOutput = '',
    string $scanError = '',
): void {
    $dir = sys_get_temp_dir().'/orbit-runtime-containers-docker-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $scanOutput = var_export($scanOutput, true);
    $scanError = var_export($scanError, true);

    $script = <<<PHP
        #!/usr/bin/env php
        <?php

        \$args = array_slice(\$argv, 1);

        if (\$args === ['--version']) {
            exit({$versionExit});
        }

        if (
            array_slice(\$args, 0, 3) === ['container', 'ls', '--all']
            && in_array('label=orbit.managed=true', \$args, true)
            && in_array('label=orbit.container.kind=app-runtime', \$args, true)
            && in_array('--format', \$args, true)
        ) {
            fwrite(STDOUT, {$scanOutput});
            fwrite(STDERR, {$scanError});
            exit({$scanExit});
        }

        fwrite(STDERR, 'unexpected docker invocation: '.implode(' ', \$args));
        exit(99);
        PHP;

    file_put_contents("{$dir}/docker", $script);
    chmod("{$dir}/docker", 0o755);
    putenv("PATH={$dir}:".(getenv('PATH') ?: ''));
}

function delete_runtime_containers_fake_docker(string $path): void
{
    @unlink("{$path}/docker");
    @rmdir($path);
}
