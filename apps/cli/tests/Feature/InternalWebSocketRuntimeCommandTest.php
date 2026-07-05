<?php

declare(strict_types=1);

use App\Commands\Internal\WebSocketRuntimeCommand;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Application as LaravelZeroApplication;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal websocket runtime command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $path = getenv('PATH');
        putenv('ORBIT_WEBSOCKET_RUNTIME_ORIGINAL_PATH='.($path === false ? '' : $path));
    });

    afterEach(function (): void {
        $path = getenv('ORBIT_WEBSOCKET_RUNTIME_ORIGINAL_PATH');
        putenv('PATH='.($path === false ? '' : $path));
        putenv('ORBIT_WEBSOCKET_RUNTIME_ORIGINAL_PATH');

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-websocket-runtime-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_websocket_runtime_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before touching runtime commands', function (): void {
        Artisan::call('internal:websocket-runtime', [
            'action' => 'image:is-self-contained',
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid actions after token validation', function (): void {
        Artisan::call('internal:websocket-runtime', [
            'action' => 'delete-everything',
            '--operation-token' => websocket_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Websocket runtime action is invalid.',
                ['field' => 'action'],
            ));
    });

    it('inspects self-contained websocket images through fixed argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'self_contained_image' => true,
        ]);

        $exitCode = Artisan::call('internal:websocket-runtime', [
            'action' => 'image:is-self-contained',
            '--operation-token' => websocket_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data(Artisan::output()))
            ->toMatchArray([
                'self_contained' => true,
                'output' => 'true',
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain(
                'docker image inspect --format {{ index .Config.Labels "orbit.websocket.self_contained" }} orbit-reverb:current',
            );
    });

    it('ensures and reads the websocket app key through fixed argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'app_key_exists' => true,
            'app_key' => 'base64:self-contained-test-key',
        ]);

        $exitCode = Artisan::call('internal:websocket-runtime', [
            'action' => 'app-key:ensure',
            '--operation-token' => websocket_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data(Artisan::output()))
            ->toBe([
                'app_key' => 'base64:self-contained-test-key',
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo install -d -m 0755 /etc/orbit/websocket')
            ->toContain('sudo test -f /etc/orbit/websocket/app.key')
            ->toContain('sudo cat /etc/orbit/websocket/app.key');
    });

    it('syncs websocket app configuration and restarts the runtime container through fixed argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'app-config:sync',
            payload: [
                'content' => "<?php\n\nreturn ['docs'];\n",
                'container' => 'orbit-websocket-app-dev-1',
            ],
        );

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'path' => '/etc/orbit/websocket/apps.php',
                'bytes' => 24,
                'restarted' => true,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo install -d -m 0755 /etc/orbit/websocket')
            ->toContain('sudo tee /etc/orbit/websocket/apps.php')
            ->toContain('sudo chmod 0644 /etc/orbit/websocket/apps.php')
            ->toContain('docker container inspect orbit-websocket-app-dev-1')
            ->toContain('docker restart orbit-websocket-app-dev-1')
            ->and(file_get_contents("{$bin}/apps.php"))
            ->toBe("<?php\n\nreturn ['docs'];\n");
    });

    it('installs websocket runtime source through fixed local argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'source_hash' => str_repeat('a', 64),
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'source:install',
            payload: [
                'source_hash' => str_repeat('a', 64),
                'archive_base64' => base64_encode('tar-bytes'),
            ],
        );
        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'action' => 'source:install',
                'source_hash' => str_repeat('a', 64),
            ])
            ->and(websocket_runtime_success_data($output)['stdout'] ?? '')
            ->toContain('__orbit_websocket_source_timing setup')
            ->toContain('__orbit_websocket_source_timing composer')
            ->and($calls)
            ->toContain(
                'sudo install -d -m 0755 /opt/orbit/websocket /opt/orbit/websocket/releases /opt/orbit/websocket/shared /etc/orbit/websocket',
            )
            ->toContain('sudo tee /etc/orbit/websocket/apps.php')
            ->toContain('sudo rm -rf /opt/orbit/websocket/releases/'.str_repeat('a', 64))
            ->toContain('sudo tar -xf - -C /opt/orbit/websocket/releases/'.str_repeat('a', 64))
            ->toContain('sudo find /opt/orbit/websocket/releases/'.str_repeat('a', 64).' -type d -exec chmod 0755 {} +')
            ->toContain('sudo chmod 0755 /opt/orbit/websocket/releases/'.str_repeat('a', 64).'/artisan')
            ->toContain('composer --version')
            ->toContain(
                'sudo env COMPOSER_ALLOW_SUPERUSER=1 composer --working-dir /opt/orbit/websocket/releases/'
                .str_repeat('a', 64)
                .' install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress',
            )
            ->toContain('sudo ln -sfn releases/'.str_repeat('a', 64).' /opt/orbit/websocket/current')
            ->and(file_get_contents("{$bin}/source.tar"))
            ->toBe('tar-bytes');
    });

    it('probes websocket runtime bind posture through fixed argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
            'container_running' => true,
            'env_host' => '10.6.0.44',
            'cmd' => 'reverb:start --host=10.6.0.44 --port=8080',
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'doctor:runtime-probe',
            payload: [
                'container' => 'orbit-websocket-app-dev-1',
            ],
        );

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'exists' => '1',
                'running' => 'true',
                'env_host' => '10.6.0.44',
                'cmd_host' => '10.6.0.44',
                'stdout' => "exists=1\nrunning=true\nenv_host=10.6.0.44\ncmd_host=10.6.0.44\n",
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker container inspect orbit-websocket-app-dev-1')
            ->toContain('docker container inspect --format {{.State.Running}} orbit-websocket-app-dev-1')
            ->toContain(
                'docker container inspect --format {{range .Config.Env}}{{println .}}{{end}} orbit-websocket-app-dev-1',
            )
            ->toContain(
                'docker container inspect --format {{range .Config.Cmd}}{{print . " "}}{{end}} orbit-websocket-app-dev-1',
            );
    });

    it('probes websocket Redis reachability through docker exec stdin', function (): void {
        $bin = install_websocket_runtime_fake_bin();

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'doctor:redis-probe',
            payload: [
                'container' => 'orbit-websocket-app-dev-1',
            ],
        );

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toBe(['ok' => true])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker exec -i orbit-websocket-app-dev-1 php')
            ->and(file_get_contents("{$bin}/redis-probe.php"))
            ->toContain('fsockopen($host, $port, $errno, $errstr, 2)');
    });

    it('applies websocket runtime containers through fixed docker argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => false,
            'network_exists' => false,
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'container:apply',
            payload: [
                'spec' => websocket_runtime_container_spec_payload(),
            ],
        );

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'action' => 'container:apply',
                'container' => 'orbit-websocket-app-dev-1',
                'outcome' => 'created',
                'had_existing_container' => false,
                'changed' => true,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker network inspect orbit-network')
            ->toContain(
                'docker network create --label orbit.managed=true --label orbit.network.kind=runtime orbit-network',
            )
            ->toContain('docker container inspect --format {{json .}} orbit-websocket-app-dev-1')
            ->toContain('docker run -d --pull never --name orbit-websocket-app-dev-1')
            ->toContain('--label orbit.container.kind=websocket-runtime')
            ->toContain('--label orbit.websocket.spec_hash='.str_repeat('b', 64))
            ->toContain('--entrypoint sh')
            ->toContain('-lc php artisan reverb:start --host=10.6.0.44 --port=8080');
    });
});

function websocket_runtime_signed_operation_token(
    string $id = 'websocket-runtime',
    string $node = 'websocket',
    string $command = 'internal:websocket-runtime',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: websocket_runtime_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function websocket_runtime_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{int, string}
 */
function run_websocket_runtime_command(string $action, array $payload): array
{
    $command = app(WebSocketRuntimeCommand::class);
    $application = app();

    if (! $application instanceof LaravelZeroApplication) {
        throw new RuntimeException('The internal command test must run inside the Laravel Zero application.');
    }

    $command->setLaravel($application);
    $output = new BufferedOutput;
    $input = new ArrayInput([
        'action' => $action,
        '--operation-token' => websocket_runtime_signed_operation_token(),
        '--json' => true,
    ]);
    $input->setStream(fopen(
        filename: 'data://text/plain,'.rawurlencode(json_encode($payload, JSON_THROW_ON_ERROR)),
        mode: 'r',
    ));

    return [$command->run($input, $output), $output->fetch()];
}

/**
 * @return array<string, mixed>
 */
function websocket_runtime_success_data(string $output): array
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
 * @return array<string, mixed>
 */
function websocket_runtime_container_spec_payload(): array
{
    return [
        'name' => 'orbit-websocket-app-dev-1',
        'image' => 'orbit-reverb:current',
        'network' => 'orbit-network',
        'restart_policy' => 'unless-stopped',
        'backend_name' => '10.6.0.44',
        'redis_node_id' => 123,
        'working_directory' => '/app',
        'command' => 'php artisan reverb:start --host=10.6.0.44 --port=8080',
        'environment' => [
            'REVERB_SERVER_HOST' => '10.6.0.44',
        ],
        'mounts' => [
            [
                'source' => '/opt/orbit/websocket/current',
                'target' => '/app',
                'read_only' => false,
            ],
        ],
        'network_aliases' => ['orbit-websocket-app-dev-1'],
        'expected_hash' => str_repeat('b', 64),
    ];
}

/**
 * @param  array{self_contained_image?: bool, app_key_exists?: bool, app_key?: string, container_exists?: bool, container_running?: bool, env_host?: string, cmd?: string, network_exists?: bool, source_hash?: string}  $options
 */
function install_websocket_runtime_fake_bin(array $options = []): string
{
    $dir = sys_get_temp_dir().'/orbit-websocket-runtime-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $selfContainedImage = $options['self_contained_image'] ?? false;
    $appKeyExists = $options['app_key_exists'] ?? false;
    $appKey = $options['app_key'] ?? '';
    $containerExists = $options['container_exists'] ?? false;
    $containerRunning = $options['container_running'] ?? false;
    $networkExists = $options['network_exists'] ?? true;
    $sourceHash = $options['source_hash'] ?? str_repeat('a', 64);
    file_put_contents("{$dir}/app-key", $appKey);
    file_put_contents("{$dir}/app-key-exists", $appKeyExists ? '1' : '0');
    file_put_contents("{$dir}/apps.php", '');
    file_put_contents("{$dir}/cmd", $options['cmd'] ?? '');
    file_put_contents("{$dir}/container-exists", $containerExists ? '1' : '0');
    file_put_contents("{$dir}/container-running", $containerRunning ? 'true' : 'false');
    file_put_contents("{$dir}/env-host", $options['env_host'] ?? '');
    file_put_contents("{$dir}/network-exists", $networkExists ? '1' : '0');
    file_put_contents("{$dir}/redis-probe.php", '');
    file_put_contents("{$dir}/self-contained-image", $selfContainedImage ? 'true' : 'false');
    file_put_contents("{$dir}/source-hash", $sourceHash);
    file_put_contents("{$dir}/source.tar", '');

    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename($argv[0]).' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        $args = array_slice($argv, 1);
        if ($args === ['network', 'inspect', 'orbit-network']) {
            exit(trim(file_get_contents(__DIR__.'/network-exists')) === '1' ? 0 : 1);
        }
        if ($args === ['network', 'create', '--label', 'orbit.managed=true', '--label', 'orbit.network.kind=runtime', 'orbit-network']) {
            file_put_contents(__DIR__.'/network-exists', '1');
            exit(0);
        }
        if ($args === ['container', 'inspect', 'orbit-websocket-app-dev-1']) {
            exit(trim(file_get_contents(__DIR__.'/container-exists')) === '1' ? 0 : 1);
        }
        if ($args === ['container', 'inspect', '--format', '{{json .}}', 'orbit-websocket-app-dev-1']) {
            if (trim(file_get_contents(__DIR__.'/container-exists')) !== '1') {
                fwrite(STDERR, 'Error: No such container');
                exit(1);
            }
            echo json_encode([
                'Config' => [
                    'Labels' => [
                        'orbit.websocket.spec_hash' => str_repeat('b', 64),
                    ],
                ],
                'State' => [
                    'Running' => true,
                ],
            ]);
            exit(0);
        }
        if ($args === ['container', 'inspect', '--format', '{{.State.Running}}', 'orbit-websocket-app-dev-1']) {
            echo file_get_contents(__DIR__.'/container-running');
            exit(0);
        }
        if ($args === ['container', 'inspect', '--format', '{{range .Config.Env}}{{println .}}{{end}}', 'orbit-websocket-app-dev-1']) {
            $host = trim(file_get_contents(__DIR__.'/env-host'));
            echo $host === '' ? '' : "REVERB_SERVER_HOST={$host}\n";
            exit(0);
        }
        if ($args === ['container', 'inspect', '--format', '{{range .Config.Cmd}}{{print . " "}}{{end}}', 'orbit-websocket-app-dev-1']) {
            echo file_get_contents(__DIR__.'/cmd');
            exit(0);
        }
        if ($args === ['exec', '-i', 'orbit-websocket-app-dev-1', 'php']) {
            file_put_contents(__DIR__.'/redis-probe.php', stream_get_contents(STDIN));
            exit(0);
        }
        if ($args === ['restart', 'orbit-websocket-app-dev-1']) {
            exit(0);
        }
        if (($args[0] ?? null) === 'run' && in_array('orbit-websocket-app-dev-1', $args, true)) {
            file_put_contents(__DIR__.'/container-exists', '1');
            exit(0);
        }
        echo file_get_contents(__DIR__.'/self-contained-image');
        PHP);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename($argv[0]).' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        $args = array_slice($argv, 1);
        $sourceHash = trim(file_get_contents(__DIR__.'/source-hash'));
        if ($args === ['test', '-f', '/etc/orbit/websocket/app.key']) {
            exit(trim(file_get_contents(__DIR__.'/app-key-exists')) === '1' ? 0 : 1);
        }
        if ($args === ['test', '-f', '/etc/orbit/websocket/apps.php']) {
            exit(trim(file_get_contents(__DIR__.'/apps.php')) === '' ? 1 : 0);
        }
        if ($args === ['test', '-f', '/opt/orbit/websocket/shared/.env']) {
            exit(is_file(__DIR__.'/shared.env') ? 0 : 1);
        }
        if ($args === ['test', '-f', "/opt/orbit/websocket/releases/{$sourceHash}/vendor/autoload.php"]) {
            exit(1);
        }
        if ($args === ['cat', '/etc/orbit/websocket/app.key']) {
            echo file_get_contents(__DIR__.'/app-key');
            exit(0);
        }
        if ($args === ['cat', "/opt/orbit/websocket/releases/{$sourceHash}/.orbit-websocket-source-hash"]) {
            exit(1);
        }
        if ($args === ['tee', '/etc/orbit/websocket/app.key']) {
            file_put_contents(__DIR__.'/app-key', stream_get_contents(STDIN));
            file_put_contents(__DIR__.'/app-key-exists', '1');
            exit(0);
        }
        if ($args === ['tee', '/etc/orbit/websocket/apps.php']) {
            file_put_contents(__DIR__.'/apps.php', stream_get_contents(STDIN));
            exit(0);
        }
        if ($args === ['tee', '/opt/orbit/websocket/shared/.env']) {
            file_put_contents(__DIR__.'/shared.env', stream_get_contents(STDIN));
            exit(0);
        }
        if ($args === ['tee', "/opt/orbit/websocket/releases/{$sourceHash}/.orbit-websocket-source-hash"]) {
            file_put_contents(__DIR__.'/installed-source-hash', stream_get_contents(STDIN));
            exit(0);
        }
        if ($args === ['tar', '-xf', '-', '-C', "/opt/orbit/websocket/releases/{$sourceHash}"]) {
            file_put_contents(__DIR__.'/source.tar', stream_get_contents(STDIN));
            exit(0);
        }
        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    file_put_contents("{$dir}/composer", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename($argv[0]).' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        exit(0);
        PHP);
    chmod(filename: "{$dir}/composer", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_websocket_runtime_fake_bin(string $path): void
{
    foreach ([
        'docker',
        'sudo',
        'calls.log',
        'app-key',
        'app-key-exists',
        'apps.php',
        'cmd',
        'composer',
        'container-exists',
        'container-running',
        'env-host',
        'installed-source-hash',
        'network-exists',
        'redis-probe.php',
        'self-contained-image',
        'shared.env',
        'source-hash',
        'source.tar',
    ] as $file) {
        $filePath = "{$path}/{$file}";

        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}
