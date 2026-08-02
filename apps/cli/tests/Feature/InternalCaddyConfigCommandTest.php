<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
describe('internal caddy config command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance(\App\Services\Executor\OperationTokenGuard::class);
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $this->originalPath = $originalPath === false ? '' : $originalPath;
        $home = getenv('HOME');
        $this->originalHome = is_string($home) ? $home : null;
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        $this->originalEnvHome = $_ENV['HOME'] ?? null;
        $dockerHost = getenv('DOCKER_HOST');
        $this->originalDockerHost = is_string($dockerHost) ? $dockerHost : null;
        $hostPathPrefix = getenv('ORBIT_HOST_PATH_PREFIX');
        $this->originalHostPathPrefix = is_string($hostPathPrefix) ? $hostPathPrefix : null;
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");
        caddy_config_restore_home(
            $this->originalHome,
            $this->originalServerHome,
            $this->originalEnvHome,
        );
        $this->originalDockerHost === null ? putenv('DOCKER_HOST') : putenv("DOCKER_HOST={$this->originalDockerHost}");
        $this->originalHostPathPrefix === null
            ? putenv('ORBIT_HOST_PATH_PREFIX')
            : putenv("ORBIT_HOST_PATH_PREFIX={$this->originalHostPathPrefix}");
        putenv('ORBIT_CADDY_CONFIG_MISSING_DIRS');
        putenv('ORBIT_CADDY_CONFIG_MISSING_FILES');
        putenv('ORBIT_CADDY_CONFIG_READ_GLOBAL');
        unset($_SERVER['ORBIT_CADDY_CONFIG_MISSING_DIRS']);
        unset($_SERVER['ORBIT_CADDY_CONFIG_MISSING_FILES']);
        unset($_SERVER['ORBIT_CADDY_CONFIG_READ_GLOBAL']);

        $fakeBinPaths = glob(caddy_config_temp_prefix('bin').'*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_caddy_config_fake_bin($dir);
        }

        $fakeHomes = glob(caddy_config_temp_prefix('home').'*');

        foreach ($fakeHomes === false ? [] : $fakeHomes as $dir) {
            delete_caddy_config_directory($dir);
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
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
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

    it('cleans interrupted fake command stdin scratch files', function (): void {
        $bin = install_caddy_config_fake_bin();
        file_put_contents("{$bin}/read-global.txt", 'global');
        $process = new Process(["{$bin}/cat"]);
        $process->mustRun();
        touch("{$bin}/stdin.current");

        expect($process->getOutput())
            ->toBe('global')
            ->and($bin)
            ->toStartWith(caddy_config_temp_prefix('bin'));

        delete_caddy_config_fake_bin($bin);

        expect($bin)->not->toBeDirectory();
    });

    it('writes site configs and force reloads unchanged TLS material through fixed argv commands', function (): void {
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
                'docker exec orbit-caddy caddy reload --force --config /etc/caddy/Caddyfile --adapter caddyfile --address localhost:2019',
            )
            ->and(file_get_contents("{$bin}/stdin.log"))
            ->toContain("docs.test {\n  respond ok\n}");
    });

    it('manages ephemeral runtime markers and persistent activity through fixed argv commands', function (): void {
        $bin = install_caddy_config_fake_bin();
        caddy_config_fake_container_inspect($bin, [
            'Mounts' => [[
                'Source' => '/var/lib/orbit/caddy/data',
                'Destination' => '/data/caddy',
            ]],
        ]);
        [$awakeExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-awake',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-awake'),
                '--json' => true,
            ],
            json_encode(['key' => 'workspace-42'], JSON_THROW_ON_ERROR),
        );
        [$statesExitCode, $statesOutput] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-states',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-states'),
                '--json' => true,
            ],
            json_encode(['keys' => ['workspace-42']], JSON_THROW_ON_ERROR),
        );
        [$asleepExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-asleep',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-asleep'),
                '--json' => true,
            ],
            json_encode(['key' => 'workspace-42'], JSON_THROW_ON_ERROR),
        );
        [, $asleepStatesOutput] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-states',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-states'),
                '--json' => true,
            ],
            json_encode(['keys' => ['workspace-42']], JSON_THROW_ON_ERROR),
        );

        $states = json_decode($statesOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $asleepStates = json_decode($asleepStatesOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect([$awakeExitCode, $statesExitCode, $asleepExitCode])
            ->toBe([0, 0, 0])
            ->and($states['success']['data']['states'][0] ?? null)
            ->toBe([
                'key' => 'workspace-42',
                'awake' => true,
                'hibernated' => false,
                'cold' => true,
                'last_activity_at' => 1_700_000_000,
            ])
            ->and($asleepStates['success']['data']['states'][0] ?? null)
            ->toBe([
                'key' => 'workspace-42',
                'awake' => false,
                'hibernated' => true,
                'cold' => true,
                'last_activity_at' => 1_700_000_000,
            ])
            ->and($calls)
            ->toContain('install -d -m 0755 /var/lib/orbit/caddy/data/orbit/hibernation')
            ->toContain('docker exec orbit-caddy touch /dev/shm/orbit/hibernation/workspace-42.awake')
            ->toContain('docker exec orbit-caddy rm -f /dev/shm/orbit/hibernation/workspace-42.awake')
            ->toContain('docker exec orbit-caddy touch /dev/shm/orbit/hibernation/workspace-42.asleep');
    });

    it('manages persistent cold runtime markers through fixed argv commands', function (): void {
        $bin = install_caddy_config_fake_bin();
        caddy_config_fake_container_inspect($bin, [
            'Mounts' => [[
                'Source' => '/var/lib/orbit/caddy/data',
                'Destination' => '/data/caddy',
            ]],
        ]);

        [$coldExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-cold',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-cold'),
                '--json' => true,
            ],
            json_encode(['key' => 'workspace-42'], JSON_THROW_ON_ERROR),
        );
        [$warmExitCode] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-warm',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-warm'),
                '--json' => true,
            ],
            json_encode(['key' => 'workspace-42'], JSON_THROW_ON_ERROR),
        );

        expect([$coldExitCode, $warmExitCode])
            ->toBe([0, 0])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('touch /var/lib/orbit/caddy/data/orbit/hibernation/workspace-42.cold')
            ->toContain('rm -f /var/lib/orbit/caddy/data/orbit/hibernation/workspace-42.cold');
    });

    it('rejects unsafe runtime hibernation keys', function (): void {
        [$exitCode, $output] = run_internal_caddy_config_command(
            [
                'action' => 'runtime-awake',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-runtime-invalid'),
                '--json' => true,
            ],
            json_encode(['key' => '../workspace-42'], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Caddy runtime key is invalid.',
                ['field' => 'key'],
            ));
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
                'rm -f /Users/nckrtl/.config/orbit/agent/caddy/sites/paseo.nmbp.caddy /Users/nckrtl/.config/orbit/agent/caddy/sites/paseo.nmbp.backend.caddy /Users/nckrtl/.config/orbit/certs/paseo.nmbp.crt /Users/nckrtl/.config/orbit/certs/paseo.nmbp.key',
            );
    });

    it('uses the mounted host path prefix for Caddy bind-mount sources', function (): void {
        putenv('ORBIT_HOST_PATH_PREFIX=/mnt/orbit-host');
        $bin = install_caddy_config_fake_bin();
        caddy_config_fake_container_inspect($bin, [
            'Mounts' => [
                [
                    'Source' => '/etc/caddy/sites',
                    'Destination' => '/etc/caddy/sites',
                ],
                [
                    'Source' => '/etc/orbit',
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
                'domain' => 'hauzer.app',
                'content' => "http://hauzer.app {\n  reverse_proxy http://10.6.0.13:8081\n}\n",
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($writeOutput, associative: true, flags: JSON_THROW_ON_ERROR);
        $calls = file_get_contents("{$bin}/calls.log");

        expect($writeExitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'] ?? null)
            ->toBe('/mnt/orbit-host/etc/caddy/sites/hauzer.app.caddy')
            ->and($calls)
            ->toContain('tee /mnt/orbit-host/etc/caddy/sites/hauzer.app.caddy')
            ->not->toContain('tee /etc/caddy/sites/hauzer.app.caddy');
    });

    it('uses the local OrbStack socket when resolving Caddy bind mounts and reloading', function (): void {
        $home = caddy_config_fake_home();
        $socket = caddy_config_fake_orbstack_socket($home);
        putenv('DOCKER_HOST');
        $bin = install_caddy_config_fake_bin(requiredDockerHost: "unix://{$socket}");
        caddy_config_fake_container_inspect($bin, [
            'Mounts' => [
                [
                    'Source' => "{$home}/.config/orbit/agent/caddy/sites",
                    'Destination' => '/etc/caddy/sites',
                ],
                [
                    'Source' => "{$home}/.config/orbit",
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
                'domain' => 'smoke-happie.happie.nmbp',
                'content' => "smoke-happie.happie.nmbp {\n  reverse_proxy http://host.docker.internal:6767\n}\n",
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
        $calls = file_get_contents("{$bin}/calls.log");

        expect($writeExitCode)
            ->toBe(0)
            ->and($reloadExitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'] ?? null)
            ->toBe("{$home}/.config/orbit/agent/caddy/sites/smoke-happie.happie.nmbp.caddy")
            ->and($calls)
            ->toContain("DOCKER_HOST=unix://{$socket} docker container inspect --format {{json .}} orbit-caddy")
            ->toContain("DOCKER_HOST=unix://{$socket} docker exec orbit-caddy caddy reload")
            ->toContain("tee {$home}/.config/orbit/agent/caddy/sites/smoke-happie.happie.nmbp.caddy")
            ->not->toContain('tee /etc/caddy/sites/smoke-happie.happie.nmbp.caddy')
            ->not->toContain('/var/run/docker.sock');
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
                'rm -f /etc/caddy/sites/docs.test.caddy /etc/caddy/sites/docs.test.backend.caddy /etc/orbit/certs/docs.test.crt /etc/orbit/certs/docs.test.key',
            )
            ->toContain(
                'docker exec orbit-caddy caddy reload --force --config /etc/caddy/Caddyfile --adapter caddyfile --address localhost:2019',
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
            ->not->toContain('sudo test -d')->toContain('docker image inspect caddy:2-alpine')->toContain(
                'docker network inspect orbit-network',
            )->toContain(
                'docker run -d --pull never --name orbit-caddy --restart unless-stopped --network orbit-network',
            )->toContain('--publish 10.6.0.50:80:80')->toContain(
                '--add-host host.docker.internal:host-gateway',
            )->toContain('--network-alias orbit-caddy')->toContain('--label orbit.container.kind=caddy')->toContain(
                '--label orbit.managed=true',
            )->toContain("--label orbit.caddy.spec_hash={$expectedHash}")->toContain(
                "--mount type=bind,source={$globalCaddyfileSource},target=/etc/caddy/Caddyfile,readonly",
            )->toContain("--mount type=bind,source={$sitesSource},target=/etc/caddy/sites,readonly")->toContain(
                "--mount type=bind,source={$runPhpSource},target=/run/php",
            )->toContain('caddy:2-alpine')
            ->not->toContain('docker start orbit-caddy');
    });

    it('pulls a missing declared Caddy image before applying the container', function (): void {
        $bin = install_caddy_config_fake_bin(imageMissing: true);
        $expectedHash = str_repeat(string: 'a', times: 64);

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
            ->toContain('docker image inspect caddy:2-alpine')
            ->toContain('docker pull caddy:2-alpine')
            ->toContain('docker run -d --pull never --name orbit-caddy');
    });

    it('recreates a matching running Caddy container that lost its managed Docker network', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'a', times: 64);
        caddy_config_fake_container_inspect($bin, [
            'State' => ['Status' => 'running', 'Running' => true, 'Restarting' => false],
            'Config' => [
                'Labels' => ['orbit.caddy.spec_hash' => $expectedHash],
            ],
            'NetworkSettings' => ['Networks' => []],
        ]);

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

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['outcome'] ?? null)
            ->toBe('recreated')
            ->and($calls)
            ->toContain('docker rm -f orbit-caddy')
            ->toContain('docker run -d --pull never --name orbit-caddy');
    });

    it('recreates a matching Caddy container stuck in a Docker restart loop', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'a', times: 64);
        caddy_config_fake_container_inspect($bin, [
            'State' => ['Status' => 'restarting', 'Running' => true, 'Restarting' => true],
            'Config' => [
                'Labels' => ['orbit.caddy.spec_hash' => $expectedHash],
            ],
            'NetworkSettings' => ['Networks' => ['orbit-network' => (object) []]],
        ]);

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

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['outcome'] ?? null)
            ->toBe('recreated')
            ->and($payload['success']['data']['changed'] ?? null)
            ->toBeTrue()
            ->and($calls)
            ->toContain('docker rm -f orbit-caddy')
            ->toContain('docker run -d --pull never --name orbit-caddy')
            ->not->toContain('docker start orbit-caddy');
    });

    it('starts a stopped matching Caddy container without recreating it', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'a', times: 64);
        caddy_config_fake_container_inspect($bin, [
            'State' => ['Status' => 'exited', 'Running' => false, 'Restarting' => false],
            'Config' => [
                'Labels' => ['orbit.caddy.spec_hash' => $expectedHash],
            ],
            'NetworkSettings' => ['Networks' => ['orbit-network' => (object) []]],
        ]);

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

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['outcome'] ?? null)
            ->toBe('started')
            ->and($payload['success']['data']['changed'] ?? null)
            ->toBeTrue()
            ->and($calls)
            ->toContain('docker start orbit-caddy')
            ->not->toContain('docker rm -f orbit-caddy')
            ->not->toContain('docker run -d --pull never --name orbit-caddy');
    });

    it('treats an existing Docker network as converged while applying the Caddy container', function (): void {
        $bin = install_caddy_config_fake_bin(networkAlreadyExists: true);
        $expectedHash = str_repeat(string: 'f', times: 64);

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

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['container'] ?? null)
            ->toBe('orbit-caddy')
            ->and($calls)
            ->toContain('docker network inspect orbit-network')
            ->toContain(
                'docker network create --label orbit.managed=true --label orbit.network.kind=runtime orbit-network',
            )
            ->toContain('docker run -d --pull never --name orbit-caddy');
    });

    it('writes the global Caddyfile through the declared container bind source', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'd', times: 64);
        $configRoot = '/Users/nckrtl/.config/orbit';
        $spec = caddy_config_container_spec($expectedHash);
        $spec['mounts'][0]['source'] = "{$configRoot}/caddy/Caddyfile";
        $spec['mounts'][1]['source'] = "{$configRoot}/caddy/sites";
        $missingDirectories = "{$configRoot}/caddy:{$configRoot}/caddy/sites";
        $missingFiles = "{$configRoot}/caddy/Caddyfile";
        putenv("ORBIT_CADDY_CONFIG_MISSING_DIRS={$missingDirectories}");
        putenv("ORBIT_CADDY_CONFIG_MISSING_FILES={$missingFiles}");
        $_SERVER['ORBIT_CADDY_CONFIG_MISSING_DIRS'] = $missingDirectories;
        $_SERVER['ORBIT_CADDY_CONFIG_MISSING_FILES'] = $missingFiles;

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
            ->toContain("test -d {$configRoot}/caddy")
            ->toContain("install -d -m 0755 {$configRoot}/caddy")
            ->toContain("test -d {$configRoot}/caddy/sites")
            ->toContain("install -d -m 0755 {$configRoot}/caddy/sites")
            ->toContain("test -f {$configRoot}/caddy/Caddyfile")
            ->toContain("tee {$configRoot}/caddy/Caddyfile")
            ->toContain("chmod 0644 {$configRoot}/caddy/Caddyfile")
            ->toContain("--mount type=bind,source={$configRoot}/caddy/Caddyfile,target=/etc/caddy/Caddyfile,readonly")
            ->toContain("--mount type=bind,source={$configRoot}/caddy/sites,target=/etc/caddy/sites,readonly")
            ->not->toContain('tee /etc/caddy/Caddyfile');
    });

    it('replaces a docker-created directory at the global Caddyfile bind source before writing', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'a', times: 64);
        $configRoot = '/Users/nckrtl/.config/orbit';
        $spec = caddy_config_container_spec($expectedHash);
        $spec['mounts'][0]['source'] = "{$configRoot}/caddy/Caddyfile";
        $spec['mounts'][1]['source'] = "{$configRoot}/caddy/sites";
        // Parent dirs may be missing; the Caddyfile path itself is a directory
        // (default test -d succeeds) while test -f fails (MISSING_FILES).
        $missingDirectories = "{$configRoot}/caddy:{$configRoot}/caddy/sites";
        $missingFiles = "{$configRoot}/caddy/Caddyfile";
        putenv("ORBIT_CADDY_CONFIG_MISSING_DIRS={$missingDirectories}");
        putenv("ORBIT_CADDY_CONFIG_MISSING_FILES={$missingFiles}");
        $_SERVER['ORBIT_CADDY_CONFIG_MISSING_DIRS'] = $missingDirectories;
        $_SERVER['ORBIT_CADDY_CONFIG_MISSING_FILES'] = $missingFiles;

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
            ->toContain("test -d {$configRoot}/caddy/Caddyfile")
            ->toContain("rm -rf {$configRoot}/caddy/Caddyfile")
            ->toContain("tee {$configRoot}/caddy/Caddyfile");
    });

    it('strips obsolete intermediate_lifetime 3599d from an existing host Caddyfile during apply-container', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'f', times: 64);
        $configRoot = '/Users/nckrtl/.config/orbit';
        $dataRoot = '/var/lib/orbit/caddy/data';
        $spec = caddy_config_container_spec($expectedHash);
        $spec['mounts'] = [
            [
                'source' => "{$configRoot}/caddy/Caddyfile",
                'target' => '/etc/caddy/Caddyfile',
                'read_only' => true,
            ],
            [
                'source' => "{$configRoot}/caddy/sites",
                'target' => '/etc/caddy/sites',
                'read_only' => true,
            ],
            [
                'source' => $dataRoot,
                'target' => '/data/caddy',
                'read_only' => false,
            ],
        ];
        $legacyConfig = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
                pki {
                    ca local {
                        intermediate_lifetime 3599d
                    }
                }
            }

            custom.mini {
                respond ok
            }

            CADDY;
        $desiredConfig = <<<'CADDY'
            {
                local_certs
                admin localhost:2019
            }

            import /etc/caddy/sites/*.caddy
            CADDY;
        file_put_contents("{$bin}/read-global.txt", $legacyConfig);
        caddy_config_fake_container_inspect($bin, [
            'State' => ['Status' => 'restarting', 'Running' => true, 'Restarting' => true],
            'Config' => [
                'Labels' => ['orbit.caddy.spec_hash' => $expectedHash],
            ],
            'NetworkSettings' => ['Networks' => ['orbit-network' => (object) []]],
        ]);

        [$exitCode] = run_internal_caddy_config_command(
            [
                'action' => 'apply-container',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.apply-container'),
                '--json' => true,
            ],
            json_encode([
                'container' => $spec,
                'global_config' => $desiredConfig,
            ], JSON_THROW_ON_ERROR),
        );

        $calls = file_get_contents("{$bin}/calls.log");
        $written = file_get_contents("{$bin}/stdin.log");

        expect($exitCode)
            ->toBe(0)
            ->and($written)
            ->not->toContain('intermediate_lifetime 3599d')
            ->not->toContain('intermediate_lifetime')->toContain('custom.mini')->toContain('local_certs')->toContain(
                'import /etc/caddy/sites/*.caddy',
            )->and($calls)->toContain('docker rm -f orbit-caddy')->toContain(
                'docker run -d --pull never --name orbit-caddy',
            )
            ->not->toContain("rm -rf {$dataRoot}")
            ->not->toContain("rm -f {$dataRoot}/pki")
            ->not->toContain('root.crt')
            ->not->toContain('intermediate.crt');
    });

    it('updates an existing global Caddyfile through the declared container bind source', function (): void {
        $bin = install_caddy_config_fake_bin();
        $expectedHash = str_repeat(string: 'e', times: 64);
        $configRoot = '/Users/nckrtl/.config/orbit';
        $spec = caddy_config_container_spec($expectedHash);
        $spec['mounts'][0]['source'] = "{$configRoot}/caddy/Caddyfile";
        $spec['mounts'][1]['source'] = "{$configRoot}/caddy/sites";
        $legacyConfig = <<<'CADDY'
            legacy.test {
                respond legacy
            }

            CADDY;
        $desiredConfig = <<<'CADDY'
            (profiling_headers) {
                header {
                    X-Caddy-End "{time.now.unix_ms}"
                    defer
                }
            }

            import /etc/caddy/sites/*.caddy
            CADDY;
        file_put_contents("{$bin}/read-global.txt", $legacyConfig);

        [$exitCode] = run_internal_caddy_config_command(
            [
                'action' => 'apply-container',
                '--operation-token' => caddy_config_signed_operation_token(id: 'caddy-config.apply-container'),
                '--json' => true,
            ],
            json_encode([
                'container' => $spec,
                'global_config' => $desiredConfig,
            ], JSON_THROW_ON_ERROR),
        );

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($calls)
            ->toContain("test -f {$configRoot}/caddy/Caddyfile")
            ->toContain("cat {$configRoot}/caddy/Caddyfile")
            ->toContain("tee {$configRoot}/caddy/Caddyfile")
            ->toContain("chmod 0644 {$configRoot}/caddy/Caddyfile")
            ->and(file_get_contents("{$bin}/stdin.log"))
            ->toContain('legacy.test')
            ->toContain('(profiling_headers)')
            ->toContain('import /etc/caddy/sites/*.caddy');
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
        file_put_contents(filename: "{$targetDirectory}/Caddyfile", data: "import /etc/caddy/sites/*.caddy\n");

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

function install_caddy_config_fake_bin(
    ?string $requiredDockerHost = null,
    bool $networkAlreadyExists = false,
    bool $imageMissing = false,
): string {
    $realUtilities = [
        '__REAL_CAT__' => escapeshellarg(caddy_config_real_utility_path('cat')),
        '__REAL_RM__' => escapeshellarg(caddy_config_real_utility_path('rm')),
    ];
    $dir = caddy_config_temp_prefix('bin').bin2hex(random_bytes(8));
    mkdir($dir);
    file_put_contents("{$dir}/required-docker-host.txt", $requiredDockerHost ?? '');

    if ($networkAlreadyExists) {
        touch("{$dir}/network-already-exists");
    }
    if ($imageMissing) {
        touch("{$dir}/image-missing");
    }
    file_put_contents("{$dir}/sudo", strtr(<<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        printf 'sudo %s\n' "$*" >>"$dir/calls.log"
        __REAL_CAT__ >"$dir/stdin.current"
        [ ! -s "$dir/stdin.current" ] || __REAL_CAT__ "$dir/stdin.current" >>"$dir/stdin.log"
        __REAL_RM__ -f "$dir/stdin.current"

        [ "${1:-}" != -n ] || shift

        if [ "${1:-}" = test ] && [ "${2:-}" = -d ]; then
            case ":${ORBIT_CADDY_CONFIG_MISSING_DIRS:-}:" in
                *":${3:-}:"*) exit 1 ;;
            esac
        fi

        if [ "${1:-}" = test ] && [ "${2:-}" = -f ]; then
            case ":${ORBIT_CADDY_CONFIG_MISSING_FILES:-}:" in
                *":${3:-}:"*) exit 1 ;;
            esac
        fi
        BASH, $realUtilities));
    foreach (['cat', 'chmod', 'install', 'rm', 'stat', 'tee', 'test', 'touch'] as $command) {
        file_put_contents("{$dir}/{$command}", strtr(<<<'BASH'
            #!/usr/bin/env bash
            dir="$(cd "$(dirname "$0")" && pwd)"
            command="$(basename "$0")"
            printf '%s %s\n' "$command" "$*" >>"$dir/calls.log"
            __REAL_CAT__ >"$dir/stdin.current"
            [ ! -s "$dir/stdin.current" ] || __REAL_CAT__ "$dir/stdin.current" >>"$dir/stdin.log"
            __REAL_RM__ -f "$dir/stdin.current"

            if [ "$command" = cat ] && [ -f "$dir/read-global.txt" ]; then
                __REAL_CAT__ "$dir/read-global.txt"
            fi

            if [ "$command" = stat ]; then
                printf '1700000000\n'
            fi

            if [ "$command" = test ] && [ "${1:-}" = -d ]; then
                case ":${ORBIT_CADDY_CONFIG_MISSING_DIRS:-}:" in
                    *":${2:-}:"*) exit 1 ;;
                esac
            fi

            if [ "$command" = test ] && [ "${1:-}" = -f ]; then
                case ":${ORBIT_CADDY_CONFIG_MISSING_FILES:-}:" in
                    *":${2:-}:"*) exit 1 ;;
                esac
            fi
            BASH, $realUtilities));
        chmod(filename: "{$dir}/{$command}", permissions: 0o755);
    }
    file_put_contents("{$dir}/docker", strtr(<<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        docker_host="${DOCKER_HOST:-}"
        required_docker_host="$(__REAL_CAT__ "$dir/required-docker-host.txt")"
        printf 'DOCKER_HOST=%s docker %s\n' "$docker_host" "$*" >>"$dir/calls.log"

        if [ -n "$required_docker_host" ] && [ "$docker_host" != "$required_docker_host" ]; then
            printf 'failed to connect to the docker API at unix:///var/run/docker.sock' >&2
            exit 1
        fi

        if [ "${1:-}" = network ] && [ "${2:-}" = inspect ] && [ -f "$dir/network-already-exists" ]; then
            printf 'network not found' >&2
            exit 1
        fi

        if [ "${1:-}" = network ] && [ "${2:-}" = create ] && [ -f "$dir/network-already-exists" ]; then
            printf 'Error response from daemon: network with name orbit-network already exists' >&2
            exit 1
        fi

        if [ "${1:-}" = image ] && [ "${2:-}" = inspect ] && [ -f "$dir/image-missing" ] && [ ! -f "$dir/image-pulled" ]; then
            printf 'image not found' >&2
            exit 1
        fi

        if [ "${1:-}" = pull ]; then
            touch "$dir/image-pulled"
        fi

        if [ "${1:-}" = rm ]; then
            __REAL_RM__ -f "$dir/container-inspect.json"
        fi

        if [ "${1:-}" = run ] || [ "${1:-}" = start ]; then
            printf '%s\n' '{"State":{"Status":"running","Running":true,"Restarting":false},"Config":{"Labels":{}},"NetworkSettings":{"Networks":{"orbit-network":{}}},"Mounts":[]}' >"$dir/container-inspect.json"
        fi

        if [ "${1:-}" = container ] && [ "${2:-}" = inspect ] && [ -f "$dir/container-inspect.json" ]; then
            __REAL_CAT__ "$dir/container-inspect.json"
        fi

        if [ "${1:-}" = exec ] && [ "${2:-}" = orbit-caddy ]; then
            command="${3:-}"
            path="${@: -1}"
            marker="$dir/runtime-marker-${path##*/}"

            if [ "$command" = touch ]; then
                : >"$marker"
            fi

            if [ "$command" = rm ]; then
                __REAL_RM__ -f "$marker"
            fi

            if [ "$command" = test ]; then
                [ -f "$marker" ]
                exit $?
            fi
        fi
        BASH, $realUtilities));
    chmod(filename: "{$dir}/sudo", permissions: 0o755);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function caddy_config_real_utility_path(string $utility): string
{
    $path = getenv('PATH');

    foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
        if ($directory === '') {
            $directory = getcwd();
        }

        if (! is_string($directory)) {
            continue;
        }

        $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$utility;

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException("Unable to resolve the real {$utility} utility.");
}

/**
 * @param  array<string, mixed>  $inspection
 */
function caddy_config_fake_container_inspect(string $bin, array $inspection): void
{
    file_put_contents("{$bin}/container-inspect.json", json_encode($inspection, JSON_THROW_ON_ERROR));
}

function caddy_config_fake_home(): string
{
    $home = caddy_config_temp_prefix('home').bin2hex(random_bytes(8));
    mkdir($home);
    putenv("HOME={$home}");
    $_SERVER['HOME'] = $home;
    $_ENV['HOME'] = $home;

    return $home;
}

function caddy_config_temp_prefix(string $kind): string
{
    return sys_get_temp_dir()."/orbit-caddy-config-{$kind}-".getmypid().'-';
}

function caddy_config_fake_orbstack_socket(string $home): string
{
    $runDirectory = "{$home}/.orbstack/run";
    mkdir($runDirectory, recursive: true);
    $socket = "{$runDirectory}/docker.sock";
    touch($socket);

    return $socket;
}

function caddy_config_restore_home(?string $home, ?string $serverHome, ?string $envHome): void
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

/** @mago-expect lint:halstead */
function delete_caddy_config_fake_bin(string $path): void
{
    delete_caddy_config_file("{$path}/sudo");
    delete_caddy_config_file("{$path}/cat");
    delete_caddy_config_file("{$path}/chmod");
    delete_caddy_config_file("{$path}/install");
    delete_caddy_config_file("{$path}/rm");
    delete_caddy_config_file("{$path}/stat");
    delete_caddy_config_file("{$path}/tee");
    delete_caddy_config_file("{$path}/test");
    delete_caddy_config_file("{$path}/touch");
    delete_caddy_config_file("{$path}/docker");
    delete_caddy_config_file("{$path}/calls.log");
    delete_caddy_config_file("{$path}/stdin.log");
    delete_caddy_config_file("{$path}/stdin.current");
    delete_caddy_config_file("{$path}/container-inspect.json");
    delete_caddy_config_file("{$path}/read-global.txt");
    delete_caddy_config_file("{$path}/required-docker-host.txt");
    delete_caddy_config_file("{$path}/network-already-exists");
    delete_caddy_config_file("{$path}/image-missing");
    delete_caddy_config_file("{$path}/image-pulled");

    $runtimeMarkers = glob("{$path}/runtime-marker-*");

    foreach ($runtimeMarkers === false ? [] : $runtimeMarkers as $runtimeMarker) {
        delete_caddy_config_file($runtimeMarker);
    }

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

function delete_caddy_config_directory(string $path): void
{
    if (! is_dir($path)) {
        delete_caddy_config_file($path);

        return;
    }

    $entries = scandir($path);

    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        delete_caddy_config_directory("{$path}/{$entry}");
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}
