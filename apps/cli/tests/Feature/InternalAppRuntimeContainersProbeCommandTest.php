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
        $home = getenv('HOME');
        $this->originalHome = is_string($home) ? $home : null;
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $this->originalEnvHome = $_ENV['HOME'] ?? null;
        $dockerHost = getenv('DOCKER_HOST');
        $this->originalDockerHost = is_string($dockerHost) ? $dockerHost : null;
        $dockerContext = getenv('DOCKER_CONTEXT');
        $this->originalDockerContext = is_string($dockerContext) ? $dockerContext : null;
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");
        restore_runtime_containers_probe_home(
            $this->originalHome,
            $this->originalServerHome,
            $this->originalEnvHome,
        );
        $this->originalDockerHost === null ? putenv('DOCKER_HOST') : putenv("DOCKER_HOST={$this->originalDockerHost}");
        $this->originalDockerContext === null
            ? putenv('DOCKER_CONTEXT')
            : putenv("DOCKER_CONTEXT={$this->originalDockerContext}");

        foreach (glob(sys_get_temp_dir().'/orbit-runtime-containers-docker-*') ?: [] as $dir) {
            delete_runtime_containers_fake_docker($dir);
        }

        foreach (glob(sys_get_temp_dir().'/orbit-runtime-containers-home-*') ?: [] as $dir) {
            delete_runtime_containers_probe_path($dir);
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

    it('uses the local OrbStack socket when probing runtime containers', function (): void {
        $home = runtime_containers_probe_fake_home();
        $socket = runtime_containers_probe_fake_orbstack_socket($home);
        putenv('DOCKER_HOST');
        putenv('DOCKER_CONTEXT');
        $bin = install_runtime_containers_fake_docker(
            scanOutput: "orbit-app-happie\thappie\n",
            requiredDockerHost: "unix://{$socket}",
        );

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
                        'container_name' => 'orbit-app-happie',
                        'app_slug' => 'happie',
                    ],
                ],
                'error' => '',
                'stdout' => "orbit-container-scan:present\norbit-app-happie\thappie\n",
            ]))
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain("DOCKER_HOST=unix://{$socket} docker --version")
            ->toContain("DOCKER_HOST=unix://{$socket} docker container ls --all");
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
    string $requiredDockerHost = '',
): string {
    $dir = sys_get_temp_dir().'/orbit-runtime-containers-docker-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $scanOutput = var_export($scanOutput, return: true);
    $scanError = var_export($scanError, return: true);
    $requiredDockerHost = var_export($requiredDockerHost, return: true);

    $script = <<<PHP
        #!/usr/bin/env php
        <?php

        \$args = array_slice(\$argv, 1);
        \$dockerHost = getenv('DOCKER_HOST') ?: '';
        file_put_contents(__DIR__.'/calls.log', 'DOCKER_HOST='.\$dockerHost.' docker '.implode(' ', \$args).PHP_EOL, FILE_APPEND);

        if ({$requiredDockerHost} !== '' && \$dockerHost !== {$requiredDockerHost}) {
            fwrite(STDERR, 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock');
            exit(1);
        }

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
    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_runtime_containers_fake_docker(string $path): void
{
    delete_runtime_containers_probe_path("{$path}/docker");
    delete_runtime_containers_probe_path("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function runtime_containers_probe_fake_home(): string
{
    $home = sys_get_temp_dir().'/orbit-runtime-containers-home-'.bin2hex(random_bytes(8));
    mkdir($home);
    putenv("HOME={$home}");
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    return $home;
}

function runtime_containers_probe_fake_orbstack_socket(string $home): string
{
    $runDirectory = "{$home}/.orbstack/run";
    mkdir($runDirectory, recursive: true);
    $socket = "{$runDirectory}/docker.sock";
    touch($socket);

    return $socket;
}

function restore_runtime_containers_probe_home(?string $home, ?string $serverHome, ?string $envHome): void
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

function delete_runtime_containers_probe_path(string $path): void
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

        delete_runtime_containers_probe_path("{$path}/{$entry}");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}
