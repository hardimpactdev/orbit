<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal caddy config command', function (): void {
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
        putenv('ORBIT_CADDY_CONFIG_MISSING_DIRS');
        unset($_SERVER['ORBIT_CADDY_CONFIG_MISSING_DIRS']);

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-caddy-config-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_caddy_config_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_caddy_config_command([
            'action' => 'write-site',
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

    it('rejects invalid actions after token validation', function (): void {
        [$exitCode, $output] = run_internal_caddy_config_command(
            [
                'action' => 'delete',
                '--operation-token' => caddy_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['domain' => 'docs.test'], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Caddy config action is invalid.');
    });

    it('writes site configs and reloads through fixed argv commands', function (): void {
        $bin = install_caddy_config_fake_bin();

        [$writeExitCode, $writeOutput] = run_internal_caddy_config_command(
            [
                'action' => 'write-site',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.write-site'),
                '--json' => true,
            ],
            json_encode([
                'domain' => 'docs.test',
                'content' => "docs.test {\n  respond ok\n}\n",
            ], JSON_THROW_ON_ERROR),
        );
        [$reloadExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'reload',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.reload'),
                '--json' => true,
            ],
            json_encode(['container' => 'orbit-caddy'], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($writeOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($writeExitCode)
            ->toBe(0)
            ->and($reloadExitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'] ?? null)
            ->toBe('/etc/caddy/sites/docs.test.caddy')
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('tee /etc/caddy/sites/docs.test.caddy')
            ->toContain(
                'docker exec orbit-caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --address localhost:2019',
            )
            ->and(file_get_contents("{$bin}/stdin.log"))
            ->toContain("docs.test {\n  respond ok\n}");
    });

    it('writes and removes site material through the running orbit-caddy bind mounts', function (): void {
        $bin = install_caddy_config_fake_bin();
        caddy_config_fake_container_inspect($bin, [
            'Mounts' => [
                [
                    'Source' => '/Users/nckrtl/.config/orbit/agent/caddy/sites',
                    'Destination' => '/etc/caddy/sites',
                ],
                [
                    'Source' => '/Users/nckrtl/.config/orbit',
                    'Destination' => '/etc/orbit',
                ],
            ],
        ]);

        [$writeExitCode, $writeOutput] = run_internal_caddy_config_command(
            [
                'action' => 'write-site',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.write-site'),
                '--json' => true,
            ],
            json_encode([
                'domain' => 'paseo.nmbp',
                'content' => "paseo.nmbp {\n  reverse_proxy http://host.docker.internal:6767\n}\n",
            ], JSON_THROW_ON_ERROR),
        );
        [$removeExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'remove-site',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.remove-site'),
                '--json' => true,
            ],
            json_encode([
                'domain' => 'paseo.nmbp',
                'container' => 'orbit-caddy',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($writeOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($writeExitCode)
            ->toBe(0)
            ->and($removeExitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'] ?? null)
            ->toBe('/Users/nckrtl/.config/orbit/agent/caddy/sites/paseo.nmbp.caddy')
            ->and($calls)
            ->toContain('docker container inspect --format {{json .}} orbit-caddy')
            ->toContain('tee /Users/nckrtl/.config/orbit/agent/caddy/sites/paseo.nmbp.caddy')
            ->toContain(
                'rm -f /Users/nckrtl/.config/orbit/agent/caddy/sites/paseo.nmbp.caddy /Users/nckrtl/.config/orbit/certs/paseo.nmbp.crt /Users/nckrtl/.config/orbit/certs/paseo.nmbp.key',
            );
    });

    it('removes site configs, orbit tls material, and reloads caddy', function (): void {
        $bin = install_caddy_config_fake_bin();

        [$exitCode, $output] = run_internal_caddy_config_command(
            [
                'action' => 'remove-site',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.remove-site'),
                '--json' => true,
            ],
            json_encode([
                'domain' => 'docs.test',
                'container' => 'orbit-caddy',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'] ?? null)
            ->toBe('/etc/caddy/sites/docs.test.caddy')
            ->and($calls)
            ->toContain(
                'rm -f /etc/caddy/sites/docs.test.caddy /etc/orbit/certs/docs.test.crt /etc/orbit/certs/docs.test.key',
            )
            ->toContain(
                'docker exec orbit-caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile --address localhost:2019',
            );
    });

    it('applies caddy container specs through fixed argv commands', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'a', times: 64);
        $runPhpHostSource = caddy_config_host_preparation_path('/run/php');
        $missingDirectories = "/etc/caddy:/etc/caddy/sites:{$runPhpHostSource}";
        putenv("ORBIT_CADDY_CONFIG_MISSING_DIRS={$missingDirectories}");
        $_SERVER['ORBIT_CADDY_CONFIG_MISSING_DIRS'] = $missingDirectories;

        [$exitCode, $output] = run_internal_caddy_config_command(
            [
                'action' => 'apply-container',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.apply-container'),
                '--json' => true,
            ],
            json_encode([
                'container' => caddy_config_container_spec($expectedHash),
                'global_config' => "import /etc/caddy/sites/*.caddy\n",
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");
        $globalCaddyfileSource = caddy_config_docker_bind_source('/etc/caddy/Caddyfile');
        $sitesSource = caddy_config_docker_bind_source('/etc/caddy/sites');
        $runPhpSource = caddy_config_docker_bind_source('/run/php');

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['container'] ?? null)
            ->toBe('orbit-caddy')
            ->and($payload['success']['data']['expected_hash'] ?? null)
            ->toBe($expectedHash)
            ->and($calls)
            ->toContain('test -d /etc/caddy')
            ->toContain('sudo -n test -d /etc/caddy')
            ->toContain('install -d -m 0755 /etc/caddy')
            ->toContain('test -d /etc/caddy/sites')
            ->toContain('sudo -n test -d /etc/caddy/sites')
            ->toContain('install -d -m 0755 /etc/caddy/sites')
            ->toContain("test -d {$runPhpHostSource}")
            ->toContain("sudo -n test -d {$runPhpHostSource}")
            ->toContain("install -d -m 0755 {$runPhpHostSource}")
            ->not
            ->toContain('sudo test -d')
            ->toContain('docker image inspect caddy:2-alpine')
            ->toContain('docker network inspect orbit-network')
            ->toContain(
                'docker run -d --pull never --name orbit-caddy --restart unless-stopped --network orbit-network',
            )
            ->toContain('--publish 10.6.0.50:80:80')
            ->toContain('--add-host host.docker.internal:host-gateway')
            ->toContain('--network-alias orbit-caddy')
            ->toContain('--label orbit.container.kind=caddy')
            ->toContain('--label orbit.managed=true')
            ->toContain("--label orbit.caddy.spec_hash={$expectedHash}")
            ->toContain("--mount type=bind,source={$globalCaddyfileSource},target=/etc/caddy/Caddyfile,readonly")
            ->toContain("--mount type=bind,source={$sitesSource},target=/etc/caddy/sites,readonly")
            ->toContain("--mount type=bind,source={$runPhpSource},target=/run/php")
            ->toContain('caddy:2-alpine')
            ->toContain('docker start orbit-caddy');
    });

    it('does not chmod existing caddy container mount directories', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'b', times: 64);
        $runPhpHostSource = caddy_config_host_preparation_path('/run/php');

        [$exitCode] = run_internal_caddy_config_command(
            [
                'action' => 'apply-container',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.apply-container'),
                '--json' => true,
            ],
            json_encode([
                'container' => caddy_config_container_spec($expectedHash),
                'global_config' => "import /etc/caddy/sites/*.caddy\n",
            ], JSON_THROW_ON_ERROR),
        );

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($calls)
            ->toContain("test -d {$runPhpHostSource}")
            ->not->toContain('sudo test -d')
            ->not->toContain("install -d -m 0755 {$runPhpHostSource}");
    });

    it('canonicalizes docker bind mount sources before running the caddy container', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'c', times: 64);
        $workspace = make_caddy_config_realpath_workspace();
        $targetDirectory = "{$workspace}/target";
        $linkDirectory = "{$workspace}/link";

        mkdir($targetDirectory, recursive: true);
        symlink($targetDirectory, $linkDirectory);
        file_put_contents(
            filename: "{$targetDirectory}/Caddyfile",
            data: "import /etc/caddy/sites/*.caddy\n",
        );

        $spec = caddy_config_container_spec($expectedHash);
        $spec['mounts'][0]['source'] = "{$linkDirectory}/Caddyfile";

        try {
            [$exitCode] = run_internal_caddy_config_command(
                [
                    'action' => 'apply-container',
                    '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.apply-container'),
                    '--json' => true,
                ],
                json_encode([
                    'container' => $spec,
                    'global_config' => "import /etc/caddy/sites/*.caddy\n",
                ], JSON_THROW_ON_ERROR),
            );

            $calls = file_get_contents("{$bin}/calls.log");

            expect($exitCode)
                ->toBe(0)
                ->and($calls)
                ->toContain(
                    '--mount type=bind,source='
                    .caddy_config_docker_bind_source("{$targetDirectory}/Caddyfile")
                    .',target=/etc/caddy/Caddyfile,readonly',
                );
        } finally {
            delete_caddy_config_realpath_workspace($workspace);
        }
    });
});

function caddy_config_signed_operation_token(
    string $id = 'caddy-config',
    string $node = 'app-dev',
    string $command = 'internal:caddy-config',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: caddy_config_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function caddy_config_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array{
 *     name: string,
 *     image: string,
 *     network: string,
 *     restart_policy: string,
 *     published_ports: list<string>,
 *     mounts: list<array{source: string, target: string, read_only: bool}>,
 *     network_aliases: list<string>,
 *     extra_hosts: array<string, string>,
 *     expected_hash: string,
 * }
 */
function caddy_config_container_spec(string $expectedHash): array
{
    return [
        'name' => 'orbit-caddy',
        'image' => 'caddy:2-alpine',
        'network' => 'orbit-network',
        'restart_policy' => 'unless-stopped',
        'published_ports' => ['10.6.0.50:80:80'],
        'mounts' => [
            [
                'source' => '/etc/caddy/Caddyfile',
                'target' => '/etc/caddy/Caddyfile',
                'read_only' => true,
            ],
            [
                'source' => '/etc/caddy/sites',
                'target' => '/etc/caddy/sites',
                'read_only' => true,
            ],
            [
                'source' => '/run/php',
                'target' => '/run/php',
                'read_only' => false,
            ],
        ],
        'network_aliases' => ['orbit-caddy'],
        'extra_hosts' => ['host.docker.internal' => 'host-gateway'],
        'expected_hash' => $expectedHash,
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_caddy_config_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:caddy-config'] ?? null;

    if (! $command instanceof Symfony\Component\Console\Command\Command) {
        throw new RuntimeException('internal:caddy-config command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function install_caddy_config_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-caddy-config-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', 'sudo '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        $stdin = stream_get_contents(STDIN);
        if ($stdin !== '') {
            file_put_contents(__DIR__.'/stdin.log', $stdin, FILE_APPEND);
        }
        $arguments = array_slice($argv, 1);
        if (($arguments[0] ?? null) === '-n') {
            $arguments = array_slice($arguments, 1);
        }
        $missingDirectories = array_filter(explode(':', getenv('ORBIT_CADDY_CONFIG_MISSING_DIRS') ?: ''));
        if (($arguments[0] ?? null) === 'test' && ($arguments[1] ?? null) === '-d' && in_array($arguments[2] ?? '', $missingDirectories, true)) {
            exit(1);
        }
        exit(0);
        PHP);
    foreach (['cat', 'chmod', 'install', 'rm', 'tee', 'test'] as $command) {
        file_put_contents("{$dir}/{$command}", <<<'PHP'
            #!/usr/bin/env php
            <?php
            $command = basename($argv[0]);
            file_put_contents(__DIR__.'/calls.log', $command.' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
            $stdin = stream_get_contents(STDIN);
            if ($stdin !== '') {
                file_put_contents(__DIR__.'/stdin.log', $stdin, FILE_APPEND);
            }
            $missingDirectories = array_filter(explode(':', getenv('ORBIT_CADDY_CONFIG_MISSING_DIRS') ?: ''));
            if ($command === 'test' && ($argv[1] ?? null) === '-d' && in_array($argv[2] ?? '', $missingDirectories, true)) {
                exit(1);
            }
            exit(0);
            PHP);
        chmod(filename: "{$dir}/{$command}", permissions: 0o755);
    }
    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', 'docker '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        if (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'inspect') {
            $inspectPath = __DIR__.'/container-inspect.json';
            if (is_file($inspectPath)) {
                echo file_get_contents($inspectPath);
            }
        }
        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

/**
 * @param  array<string, mixed>  $inspection
 */
function caddy_config_fake_container_inspect(string $bin, array $inspection): void
{
    file_put_contents("{$bin}/container-inspect.json", json_encode($inspection, JSON_THROW_ON_ERROR));
}

function delete_caddy_config_fake_bin(string $path): void
{
    delete_caddy_config_file("{$path}/sudo");
    delete_caddy_config_file("{$path}/cat");
    delete_caddy_config_file("{$path}/chmod");
    delete_caddy_config_file("{$path}/install");
    delete_caddy_config_file("{$path}/rm");
    delete_caddy_config_file("{$path}/tee");
    delete_caddy_config_file("{$path}/test");
    delete_caddy_config_file("{$path}/docker");
    delete_caddy_config_file("{$path}/calls.log");
    delete_caddy_config_file("{$path}/stdin.log");
    delete_caddy_config_file("{$path}/container-inspect.json");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function make_caddy_config_realpath_workspace(): string
{
    $path = sys_get_temp_dir().'/orbit-caddy-config-realpath-'.bin2hex(random_bytes(8));

    mkdir($path);

    return $path;
}

function delete_caddy_config_realpath_workspace(string $path): void
{
    delete_caddy_config_file("{$path}/target/Caddyfile");

    if (is_link("{$path}/link")) {
        unlink("{$path}/link");
    }

    if (is_dir("{$path}/target")) {
        rmdir("{$path}/target");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}

function caddy_config_docker_bind_source(string $source): string
{
    $canonical = realpath($source);

    return is_string($canonical) && $canonical !== '' ? $canonical : caddy_config_host_preparation_path($source);
}

function caddy_config_host_preparation_path(string $path): string
{
    if (PHP_OS_FAMILY !== 'Darwin') {
        return $path;
    }

    if ($path === '/run') {
        return '/private/var/run';
    }

    if (str_starts_with($path, '/run/')) {
        return '/private/var/run/'.substr($path, strlen('/run/'));
    }

    return $path;
}

function delete_caddy_config_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}
