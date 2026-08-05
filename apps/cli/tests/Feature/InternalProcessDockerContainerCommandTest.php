<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @mago-expect lint:halstead
 */
describe('internal process Docker container command', function (): void {
    beforeEach(function (): void {
        configure_process_docker_container_operation_token_guard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $path = getenv('PATH');
        $this->originalPath = is_string($path) ? $path : null;
        $home = getenv('HOME');
        $this->originalHome = is_string($home) ? $home : null;
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $this->originalEnvHome = $_ENV['HOME'] ?? null;
        $dockerHost = getenv('DOCKER_HOST');
        $this->originalDockerHost = is_string($dockerHost) ? $dockerHost : null;
    });

    afterEach(function (): void {
        $this->originalPath === null ? putenv('PATH') : putenv("PATH={$this->originalPath}");
        process_docker_container_restore_home(
            $this->originalHome,
            $this->originalServerHome,
            $this->originalEnvHome,
        );
        $this->originalDockerHost === null ? putenv('DOCKER_HOST') : putenv("DOCKER_HOST={$this->originalDockerHost}");

        $fakeHomes = glob(sys_get_temp_dir().'/orbit-process-docker-home-*');

        foreach ($fakeHomes === false ? [] : $fakeHomes as $dir) {
            delete_process_docker_container_path($dir);
        }

        $fakeBins = glob(sys_get_temp_dir().'/orbit-process-docker-bin-*');

        foreach ($fakeBins === false ? [] : $fakeBins as $dir) {
            delete_process_docker_container_path($dir);
        }
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_process_docker_container_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before reading stdin', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance(\App\Services\GatewayApiClient::class);
        app()->forgetInstance(\App\Services\Executor\OperationTokenGuard::class);

        [$exitCode, $output] = run_internal_process_docker_container_command([
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('emits validation failures as strict json after token validation', function (): void {
        [$exitCode, $output] = run_internal_process_docker_container_command([
            '--operation-token' => process_docker_container_signed_operation_token(),
            '--json' => true,
        ], stdin: 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Process Docker container payload is invalid.',
            ));
    });

    it('rejects invalid Docker apply prerequisite flags before running Docker', function (): void {
        [$exitCode, $output] = run_internal_process_docker_container_command(
            [
                '--operation-token' => process_docker_container_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => 'apply',
                'prepare_prerequisites' => 'yes',
                'spec' => process_docker_container_spec_payload(),
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker container prepare_prerequisites flag is invalid.',
                ['field' => 'prepare_prerequisites'],
            ));
    });

    it('accepts Docker probe and ensure-network actions before validating their specs', function (string $action): void {
        [$exitCode, $output] = run_internal_process_docker_container_command(
            [
                '--operation-token' => process_docker_container_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => $action,
                'spec' => null,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker process container spec is invalid.',
                ['field' => 'spec'],
            ));
    })->with(['ensure-network', 'probe']);

    it('treats an existing Docker network as converged through the local OrbStack socket', function (): void {
        $home = process_docker_container_fake_home();
        $socket = process_docker_container_fake_orbstack_socket($home);
        putenv('DOCKER_HOST');
        $bin = install_process_docker_container_fake_docker_bin(requiredDockerHost: "unix://{$socket}");

        [$exitCode, $output] = run_internal_process_docker_container_command(
            [
                '--operation-token' => process_docker_container_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => 'ensure-network',
                'spec' => process_docker_container_spec_payload(),
            ], JSON_THROW_ON_ERROR),
        );

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['success']['data'] ?? null)
            ->toBe([
                'action' => 'ensure-network',
                'network' => 'orbit-network',
                'changed' => false,
            ])
            ->and($calls)
            ->toContain("DOCKER_HOST=unix://{$socket} docker network inspect orbit-network")
            ->toContain(
                "DOCKER_HOST=unix://{$socket} docker network create --label orbit.managed=true --label orbit.network.kind=runtime orbit-network",
            )
            ->not->toContain('/var/run/docker.sock');
    });

    it('checks an existing managed file bind source with elevated privileges before preparing directories', function (): void {
        $home = process_docker_container_fake_home();
        $socket = process_docker_container_fake_orbstack_socket($home);
        $configPath = "{$home}/s3.json";
        file_put_contents(filename: $configPath, data: "{}\n");
        putenv('DOCKER_HOST');
        $bin = install_process_docker_container_fake_docker_bin(requiredDockerHost: "unix://{$socket}");
        $spec = process_docker_container_spec_payload();
        $spec['mounts'] = [
            [
                'source' => $configPath,
                'target' => '/etc/seaweedfs/s3.json',
                'read_only' => true,
            ],
        ];

        [$exitCode, $output] = run_internal_process_docker_container_command(
            [
                '--operation-token' => process_docker_container_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => 'apply',
                'prepare_prerequisites' => true,
                'spec' => $spec,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and(
                json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['success']['data']['outcome']
                ?? null,
            )
            ->toBe('created')
            ->and(is_file($configPath))
            ->toBeTrue()
            ->and(file_get_contents("{$bin}/sudo-calls.log"))
            ->toContain("test -e {$configPath}")
            ->not->toContain("mkdir -p {$configPath}");
    });

    it('treats docker is-active as running when observed State.Running is true', function (): void {
        $home = process_docker_container_fake_home();
        $socket = process_docker_container_fake_orbstack_socket($home);
        putenv('DOCKER_HOST');
        $bin = install_process_docker_container_fake_docker_bin_for_is_active(
            requiredDockerHost: "unix://{$socket}",
            running: true,
        );

        $result = app(\App\Services\Processes\LocalDockerContainerAction::class)->run([
            'action' => 'is-active',
            'container' => 'orbit_docs_main_queue',
        ]);

        expect($result['data'] ?? null)
            ->toBe([
                'action' => 'is-active',
                'container' => 'orbit_docs_main_queue',
                'changed' => false,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker inspect --format {{.State.Running}} orbit_docs_main_queue');
    });

    it('rejects docker is-active when observed State.Running is false', function (): void {
        $home = process_docker_container_fake_home();
        $socket = process_docker_container_fake_orbstack_socket($home);
        putenv('DOCKER_HOST');
        install_process_docker_container_fake_docker_bin_for_is_active(
            requiredDockerHost: "unix://{$socket}",
            running: false,
        );

        expect(fn (): array => app(\App\Services\Processes\LocalDockerContainerAction::class)->run([
            'action' => 'is-active',
            'container' => 'orbit_docs_main_queue',
        ]))
            ->toThrow(\App\Services\Processes\LocalDockerContainerFailure::class);
    });
});

function configure_process_docker_container_operation_token_guard(): void
{
    app()->forgetInstance(\App\Services\Executor\OperationTokenGuard::class);
}

function process_docker_container_signed_operation_token(
    string $id = 'process-docker-container',
    string $node = 'app-dev',
    string $command = 'internal:process-docker-container',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: process_docker_container_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function process_docker_container_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function process_docker_container_fake_home(): string
{
    $home = sys_get_temp_dir().'/orbit-process-docker-home-'.bin2hex(random_bytes(8));
    mkdir($home);
    putenv("HOME={$home}");
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    return $home;
}

function process_docker_container_fake_orbstack_socket(string $home): string
{
    $runDirectory = "{$home}/.orbstack/run";
    mkdir($runDirectory, recursive: true);
    $socket = "{$runDirectory}/docker.sock";
    touch($socket);

    return $socket;
}

function install_process_docker_container_fake_docker_bin_for_is_active(
    string $requiredDockerHost,
    bool $running,
): string {
    $dir = sys_get_temp_dir().'/orbit-process-docker-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $encodedRequiredDockerHost = var_export($requiredDockerHost, return: true);
    $runningLiteral = $running ? 'true' : 'false';

    file_put_contents("{$dir}/docker", <<<PHP_WRAP
        #!/usr/bin/env php
        <?php
        \$requiredDockerHost = {$encodedRequiredDockerHost};
        \$dockerHost = getenv('DOCKER_HOST') ?: '';
        file_put_contents(__DIR__.'/calls.log', 'DOCKER_HOST='.\$dockerHost.' docker '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        if (\$requiredDockerHost !== '' && \$dockerHost !== \$requiredDockerHost) {
            fwrite(STDERR, 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock');
            exit(1);
        }
        if ((\$argv[1] ?? null) === 'inspect') {
            echo "{$runningLiteral}\\n";
            exit(0);
        }
        exit(0);
        PHP_WRAP);
    chmod("{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    $path = is_string($path) ? $path : '';
    putenv("PATH={$dir}:{$path}");

    return $dir;
}

function install_process_docker_container_fake_docker_bin(string $requiredDockerHost): string
{
    $dir = sys_get_temp_dir().'/orbit-process-docker-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $encodedRequiredDockerHost = var_export($requiredDockerHost, return: true);

    file_put_contents("{$dir}/docker", <<<PHP_WRAP
        #!/usr/bin/env php
        <?php
        \$requiredDockerHost = {$encodedRequiredDockerHost};
        \$dockerHost = getenv('DOCKER_HOST') ?: '';
        file_put_contents(__DIR__.'/calls.log', 'DOCKER_HOST='.\$dockerHost.' docker '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        if (\$requiredDockerHost !== '' && \$dockerHost !== \$requiredDockerHost) {
            fwrite(STDERR, 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock');
            exit(1);
        }
        if ((\$argv[1] ?? null) === 'network' && (\$argv[2] ?? null) === 'inspect') {
            fwrite(STDERR, 'network not found');
            exit(1);
        }
        if ((\$argv[1] ?? null) === 'network' && (\$argv[2] ?? null) === 'create') {
            fwrite(STDERR, 'Error response from daemon: network with name orbit-network already exists');
            exit(1);
        }
        exit(0);
        PHP_WRAP);
    chmod(filename: "{$dir}/docker", permissions: 0o755);
    file_put_contents("{$dir}/sudo", <<<'PHP_WRAP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/sudo-calls.log', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        exit(0);
        PHP_WRAP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv('PATH='.$dir.($path === false ? '' : ":{$path}"));

    return $dir;
}

function process_docker_container_restore_home(?string $home, ?string $serverHome, ?string $envHome): void
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

function delete_process_docker_container_path(string $path): void
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

        delete_process_docker_container_path("{$path}/{$entry}");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}

/**
 * @return array<string, mixed>
 */
function process_docker_container_spec_payload(): array
{
    return [
        'name' => 'orbit_docs_main_queue',
        'image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
        'network' => 'orbit-network',
        'restart_policy' => 'always',
        'app_slug' => 'docs',
        'workspace_slug' => null,
        'process_slug' => 'queue',
        'working_directory' => '/app',
        'command' => 'php artisan queue:work',
        'command_mode' => 'shell',
        'environment' => [],
        'mounts' => [
            [
                'source' => '/srv/docs',
                'target' => '/app',
                'read_only' => false,
            ],
        ],
        'volumes' => [],
        'ports' => [],
        'network_aliases' => ['orbit_docs_main_queue'],
        'expected_hash' => str_repeat('a', times: 64),
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_process_docker_container_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:process-docker-container'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal process Docker container command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}
