<?php

declare(strict_types=1);

use App\Commands\Internal\WebSocketRuntimeCommand;
use App\Services\Executor\OperationTokenGuard;
use Illuminate\Support\Facades\Artisan;
use LaravelZero\Framework\Application as LaravelZeroApplication;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal websocket runtime command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance(OperationTokenGuard::class);
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

        expect(Artisan::output())->json()->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects invalid actions after token validation', function (): void {
        Artisan::call('internal:websocket-runtime', [
            'action' => 'delete-everything',
            '--operation-token' => websocket_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure('validation_failed', 'Websocket runtime action is invalid.', [
                'field' => 'action',
            ]));
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

    it('pulls and aliases manifest websocket images through fixed argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'self_contained_image' => true,
        ]);
        $image = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-build@sha256:'.str_repeat('a', times: 64);

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'image:ensure',
            payload: ['image' => $image],
        );

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toBe([
                'image' => $image,
                'alias' => 'orbit-reverb:current',
                'self_contained' => true,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain("docker pull {$image}")
            ->toContain("docker tag {$image} orbit-reverb:current")
            ->toContain(
                'docker image inspect --format {{ index .Config.Labels "orbit.websocket.self_contained" }} '.$image,
            );
    });

    it('loads verified manifest websocket image artifacts without registry credentials', function (): void {
        $archive = 'websocket-image-archive';
        $image = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-build@sha256:'.str_repeat('b', times: 64);
        $sourceImage = strstr(haystack: $image, needle: '@', before_needle: true);
        $bin = install_websocket_runtime_fake_bin([
            'self_contained_image' => true,
            'image_archive' => $archive,
            'image_archive_tags' => [$sourceImage],
        ]);
        $url = 'https://artifacts.example.test/orbit-reverb-linux-amd64.tar';

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'image:ensure',
            payload: [
                'image' => $image,
                'artifact' => [
                    'url' => $url,
                    'sha256' => hash('sha256', $archive),
                ],
            ],
        );

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toBe([
                'image' => $image,
                'alias' => 'orbit-reverb:current',
                'self_contained' => true,
            ]);

        $calls = file_get_contents("{$bin}/calls.log");

        expect($calls)
            ->toContain('curl --fail --silent --show-error --location --output ')
            ->toContain($url)
            ->toContain('docker load --input ')
            ->toContain('docker image inspect --format {{ index .Config.Labels "orbit.websocket.self_contained" }} '
            .$sourceImage)
            ->toContain("docker tag {$sourceImage} orbit-reverb:current")
            ->not->toContain('docker pull');
    });

    it('rejects image artifacts whose archive tags could replace another runtime image', function (): void {
        $archive = 'websocket-image-archive';
        $bin = install_websocket_runtime_fake_bin([
            'image_archive' => $archive,
            'image_archive_tags' => [
                'ghcr.io/hardimpactdev/orbit-reverb:unexpected',
                'orbit-reverb:current',
            ],
        ]);
        $image = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-build@sha256:'.str_repeat('e', times: 64);
        $url = 'https://artifacts.example.test/orbit-reverb-linux-amd64.tar';

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'image:ensure',
            payload: [
                'image' => $image,
                'artifact' => [
                    'url' => $url,
                    'sha256' => hash('sha256', $archive),
                ],
            ],
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'websocket_runtime_image_artifact_invalid',
                'Websocket runtime image artifact does not contain exactly the expected image tag.',
                ['image' => $image],
            ));

        $calls = file_get_contents("{$bin}/calls.log");

        expect($calls)
            ->toContain('tar --extract --to-stdout --file ')
            ->not->toContain('docker load')
            ->not->toContain('docker tag');
    });

    it('does not load or alias websocket image artifacts with a mismatched checksum', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'image_archive' => 'tampered-websocket-image-archive',
        ]);
        $image = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-build@sha256:'.str_repeat('c', times: 64);
        $url = 'https://artifacts.example.test/orbit-reverb-linux-amd64.tar';

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'image:ensure',
            payload: [
                'image' => $image,
                'artifact' => [
                    'url' => $url,
                    'sha256' => str_repeat('f', times: 64),
                ],
            ],
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'websocket_runtime_image_artifact_invalid',
                'Websocket runtime image artifact checksum does not match.',
                ['url' => $url],
            ));

        $calls = file_get_contents("{$bin}/calls.log");

        expect($calls)
            ->not->toContain('docker load')
            ->not->toContain('docker tag');
    });

    it('rejects mutable manifest image references before pulling', function (): void {
        $bin = install_websocket_runtime_fake_bin();

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'image:ensure',
            payload: ['image' => 'ghcr.io/hardimpactdev/orbit-reverb:latest'],
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Websocket runtime payload is invalid.',
            ))
            ->and(file_exists("{$bin}/calls.log"))
            ->toBeFalse();
    });

    it('does not replace the runtime alias when the pulled image is not self-contained', function (): void {
        $bin = install_websocket_runtime_fake_bin();
        $image = 'ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-build@sha256:'.str_repeat('d', times: 64);

        [$exitCode, $output] = run_websocket_runtime_command(
            action: 'image:ensure',
            payload: ['image' => $image],
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'websocket_runtime_image_invalid',
                'Websocket runtime image is not self-contained.',
                ['image' => $image],
            ));

        $calls = file_get_contents("{$bin}/calls.log");

        expect($calls)
            ->toContain("docker pull {$image}")
            ->toContain(
                'docker image inspect --format {{ index .Config.Labels "orbit.websocket.self_contained" }} '.$image,
            )
            ->not->toContain("docker tag {$image} orbit-reverb:current");
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
            ->toContain('sudo chmod 0600 /etc/orbit/websocket/app.key')
            ->toContain('sudo cat /etc/orbit/websocket/app.key');
    });

    it('creates a missing websocket app key securely before writing its contents', function (): void {
        $bin = install_websocket_runtime_fake_bin();

        $exitCode = Artisan::call('internal:websocket-runtime', [
            'action' => 'app-key:ensure',
            '--operation-token' => websocket_runtime_signed_operation_token(),
            '--json' => true,
        ]);
        $calls = file_get_contents("{$bin}/calls.log");
        $secureCreate = strpos($calls, 'sudo install -m 0600 /dev/null /etc/orbit/websocket/app.key');
        $write = strpos($calls, 'sudo tee /etc/orbit/websocket/app.key');

        expect($exitCode)
            ->toBe(0)
            ->and($secureCreate)
            ->not->toBeFalse()->and($write)
            ->not->toBeFalse()->and($secureCreate)->toBeLessThan($write);
    });

    it('syncs websocket app configuration and restarts the runtime container through fixed argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'app-config:sync', payload: [
            'content' => "<?php\n\nreturn ['docs'];\n",
            'container' => 'orbit-websocket-app-dev-1',
        ]);

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
            ->toContain('sudo install -m 0600 /dev/null /etc/orbit/websocket/apps.php')
            ->toContain('sudo tee /etc/orbit/websocket/apps.php')
            ->toContain('sudo chmod 0600 /etc/orbit/websocket/apps.php')
            ->toContain('docker container inspect orbit-websocket-app-dev-1')
            ->toContain('docker restart orbit-websocket-app-dev-1')
            ->and(file_get_contents("{$bin}/apps.php"))
            ->toBe("<?php\n\nreturn ['docs'];\n");
    });

    it('installs websocket runtime source through fixed local argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'source_hash' => str_repeat('a', 64),
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'source:install', payload: [
            'source_hash' => str_repeat('a', 64),
            'archive_base64' => base64_encode('tar-bytes'),
        ]);
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
            ->toContain('sudo install -m 0600 /dev/null /etc/orbit/websocket/apps.php')
            ->toContain('sudo tee /etc/orbit/websocket/apps.php')
            ->toContain('sudo install -m 0600 /dev/null /opt/orbit/websocket/shared/.env')
            ->toContain('sudo chmod 0600 /etc/orbit/websocket/apps.php')
            ->toContain('sudo chmod 0600 /opt/orbit/websocket/shared/.env')
            ->toContain('sudo rm -rf /opt/orbit/websocket/releases/'.str_repeat('a', 64))
            ->toContain('sudo tar -xf - -C /opt/orbit/websocket/releases/'.str_repeat('a', 64))
            ->toContain(
                'sudo find /opt/orbit/websocket/releases/'.str_repeat('a', 64).' -type d -exec chmod 0755 {} +',
            )
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
            'published_bindings' => [
                ['host' => '10.6.0.44', 'port' => '8080'],
                ['host' => '0.0.0.0', 'port' => '8080'],
            ],
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'doctor:runtime-probe', payload: [
            'container' => 'orbit-websocket-app-dev-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'exists' => '1',
                'running' => 'true',
                'env_host' => '10.6.0.44',
                'cmd_host' => '10.6.0.44',
                'published_bindings' => '[{"host":"10.6.0.44","port":"8080"},{"host":"0.0.0.0","port":"8080"}]',
                'stdout' => "exists=1\nrunning=true\nenv_host=10.6.0.44\ncmd_host=10.6.0.44\npublished_bindings=[{\"host\":\"10.6.0.44\",\"port\":\"8080\"},{\"host\":\"0.0.0.0\",\"port\":\"8080\"}]\n",
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker container inspect orbit-websocket-app-dev-1')
            ->toContain('docker container inspect --format {{.State.Running}} orbit-websocket-app-dev-1')
            ->toContain(
                'docker container inspect --format {{range .Config.Env}}{{println .}}{{end}} orbit-websocket-app-dev-1',
            )
            ->toContain(
                'docker container inspect --format {{range .Config.Cmd}}{{print . " "}}{{end}} orbit-websocket-app-dev-1',
            )
            ->toContain(
                'docker container inspect --format {{with (index .NetworkSettings.Ports "8080/tcp")}}{{range .}}{{println .HostIp "|" .HostPort}}{{end}}{{end}} orbit-websocket-app-dev-1',
            );
    });

    it('probes websocket Valkey reachability through docker exec stdin', function (): void {
        $bin = install_websocket_runtime_fake_bin();

        [$exitCode, $output] = run_websocket_runtime_command(action: 'doctor:valkey-probe', payload: [
            'container' => 'orbit-websocket-app-dev-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toBe(['ok' => true])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker exec -i orbit-websocket-app-dev-1 php')
            ->and(file_get_contents("{$bin}/valkey-probe.php"))
            ->toContain('fsockopen($host, $port, $errno, $errstr, 2)');
    });

    it('applies websocket runtime containers through fixed docker argv', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => false,
            'network_exists' => false,
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'container:apply', payload: [
            'spec' => websocket_runtime_container_spec_payload(),
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'action' => 'container:apply',
                'container' => 'orbit-websocket-app-dev-1',
                'outcome' => 'created',
                'had_existing_container' => false,
                'changed' => true,
                'image_verification' => null,
            ])
            ->and(websocket_runtime_success_data($output))
            ->not
            ->toHaveKey('warning')
            ->and(websocket_runtime_success_meta($output)['warnings'] ?? null)
            ->toBeNull()
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker network inspect orbit-network')
            ->toContain(
                'docker network create --label orbit.managed=true --label orbit.network.kind=runtime orbit-network',
            )
            ->toContain('docker container inspect --format {{json .}} orbit-websocket-app-dev-1')
            ->toContain('docker run -d --pull never --name orbit-websocket-app-dev-1')
            ->toContain('--label orbit.container.kind=websocket-runtime')
            ->toContain('--label orbit.websocket.spec_hash='.str_repeat('b', 64))
            ->toContain('--publish 10.6.0.44:8080:8080')
            ->toContain('--entrypoint sh')
            ->toContain('-lc php artisan reverb:start --host=0.0.0.0 --port=8080');
    });

    it('recreates a running websocket container when orbit-reverb:current resolves to a new image id', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
            'container_running' => true,
            'container_image_id' => 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'current_image_id' => 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'container:apply', payload: [
            'spec' => websocket_runtime_container_spec_payload(),
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'action' => 'container:apply',
                'container' => 'orbit-websocket-app-dev-1',
                'outcome' => 'recreated',
                'had_existing_container' => true,
                'changed' => true,
                'image_verification' => 'differs',
            ])
            ->and(websocket_runtime_success_data($output))
            ->not
            ->toHaveKey('warning')
            ->and(websocket_runtime_success_meta($output)['warnings'] ?? null)
            ->toBeNull()
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('docker image inspect --format {{.Id}} orbit-reverb:current')
            ->toContain('docker rm -f orbit-websocket-app-dev-1')
            ->toContain('docker run -d --pull never --name orbit-websocket-app-dev-1');
    });

    it('leaves a running websocket container unchanged when it already uses the current image id', function (): void {
        $imageId = 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
            'container_running' => true,
            'container_image_id' => $imageId,
            'current_image_id' => $imageId,
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'container:apply', payload: [
            'spec' => websocket_runtime_container_spec_payload(),
        ]);

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'action' => 'container:apply',
                'container' => 'orbit-websocket-app-dev-1',
                'outcome' => 'unchanged',
                'had_existing_container' => true,
                'changed' => false,
                'image_verification' => 'matches',
            ])
            ->and(websocket_runtime_success_data($output))
            ->not->toHaveKey('warning')->and(
                websocket_runtime_success_meta($output)['warnings'] ?? null,
            )->toBeNull()->and($calls)->toContain('docker image inspect --format {{.Id}} orbit-reverb:current')->and(
                $calls,
            )
            ->not->toContain('docker rm -f orbit-websocket-app-dev-1')->and($calls)
            ->not->toContain('docker run -d --pull never --name orbit-websocket-app-dev-1');
    });

    it('leaves a running websocket container unchanged and warns when orbit-reverb:current cannot be inspected', function (
        bool $inspectFails,
        string $currentImageId,
    ): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
            'container_running' => true,
            'container_image_id' => 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
            'current_image_id' => $currentImageId,
            'image_inspect_fails' => $inspectFails,
        ]);

        [$exitCode, $output] = run_websocket_runtime_command(action: 'container:apply', payload: [
            'spec' => websocket_runtime_container_spec_payload(),
        ]);

        $calls = file_get_contents("{$bin}/calls.log");
        $warning = 'Current-image verification was skipped because orbit-reverb:current could not be inspected.';

        expect($exitCode)
            ->toBe(0)
            ->and(websocket_runtime_success_data($output))
            ->toMatchArray([
                'action' => 'container:apply',
                'container' => 'orbit-websocket-app-dev-1',
                'outcome' => 'unchanged',
                'had_existing_container' => true,
                'changed' => false,
                'image_verification' => 'could_not_verify',
                'warning' => $warning,
            ])
            ->and(websocket_runtime_success_meta($output)['warnings'] ?? null)
            ->toBe([
                [
                    'code' => 'websocket_runtime.current_image_unverified',
                    'message' => $warning,
                ],
            ])
            ->and($calls)
            ->toContain('docker image inspect --format {{.Id}} orbit-reverb:current')
            ->and($calls)
            ->not->toContain('docker rm -f orbit-websocket-app-dev-1')->and($calls)
            ->not->toContain('docker run -d --pull never --name orbit-websocket-app-dev-1');
    })->with([
        'inspect errors' => [true, 'sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'],
        'inspect empty' => [false, ''],
    ]);

    it('prints the current-image verification skip in human container-apply output', function (): void {
        $bin = install_websocket_runtime_fake_bin([
            'container_exists' => true,
            'container_running' => true,
            'container_image_id' => 'sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
            'image_inspect_fails' => true,
        ]);

        [$exitCode, $output] = run_websocket_runtime_human_command(action: 'container:apply', payload: [
            'spec' => websocket_runtime_container_spec_payload(),
        ]);

        $calls = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('outcome: unchanged')
            ->toContain('image_verification: could_not_verify')
            ->toContain(
                'warning: Current-image verification was skipped because orbit-reverb:current could not be inspected.',
            )
            ->and($calls)
            ->not->toContain('docker rm -f orbit-websocket-app-dev-1');
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
    return run_websocket_runtime_command_with_input($payload, [
        'action' => $action,
        '--operation-token' => websocket_runtime_signed_operation_token(),
        '--json' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{0: int, 1: string}
 */
function run_websocket_runtime_human_command(string $action, array $payload): array
{
    return run_websocket_runtime_command_with_input($payload, [
        'action' => $action,
        '--operation-token' => websocket_runtime_signed_operation_token(),
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function run_websocket_runtime_command_with_input(array $payload, array $arguments): array
{
    $command = app(WebSocketRuntimeCommand::class);
    $application = app();

    if (! $application instanceof LaravelZeroApplication) {
        throw new RuntimeException('The internal command test must run inside the Laravel Zero application.');
    }

    $command->setLaravel($application);
    $output = new BufferedOutput;
    $input = new ArrayInput($arguments);
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
function websocket_runtime_success_meta(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $meta */
    $meta = data_get(target: $payload, key: 'success.meta');

    if (! is_array($meta)) {
        return [];
    }

    /** @var array<string, mixed> $meta */
    return $meta;
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
        'valkey_node_id' => 123,
        'working_directory' => '/app',
        'command' => 'php artisan reverb:start --host=0.0.0.0 --port=8080',
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
        'published_ports' => ['10.6.0.44:8080:8080'],
        'expected_hash' => str_repeat('b', 64),
    ];
}

/**
 * @param  array{self_contained_image?: bool, image_archive?: string, image_archive_tags?: list<string>, app_key_exists?: bool, app_key?: string, container_exists?: bool, container_running?: bool, container_image_id?: string, current_image_id?: string, image_inspect_fails?: bool, env_host?: string, cmd?: string, published_bindings?: list<array{host: string, port: string}>, network_exists?: bool, source_hash?: string}  $options
 *
 * @mago-expect lint:cyclomatic-complexity
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
    $containerImageId = $options['container_image_id'] ?? '';
    $currentImageId = $options['current_image_id'] ?? '';
    $imageInspectFails = $options['image_inspect_fails'] ?? false;
    $networkExists = $options['network_exists'] ?? true;
    $sourceHash = $options['source_hash'] ?? str_repeat('a', times: 64);
    file_put_contents("{$dir}/app-key", $appKey);
    file_put_contents("{$dir}/app-key-exists", $appKeyExists ? '1' : '0');
    file_put_contents("{$dir}/apps.php", '');
    file_put_contents("{$dir}/cmd", $options['cmd'] ?? '');
    file_put_contents("{$dir}/container-exists", $containerExists ? '1' : '0');
    file_put_contents("{$dir}/container-running", $containerRunning ? 'true' : 'false');
    file_put_contents("{$dir}/container-image-id", $containerImageId);
    file_put_contents("{$dir}/current-image-id", $currentImageId);
    file_put_contents("{$dir}/image-inspect-fails", $imageInspectFails ? '1' : '0');
    file_put_contents("{$dir}/env-host", $options['env_host'] ?? '');
    file_put_contents("{$dir}/image.tar", websocket_runtime_image_archive($options));
    file_put_contents("{$dir}/image-manifest.json", websocket_runtime_image_manifest($options));
    $publishedBindings = array_map(
        static fn (array $binding): string => "{$binding['host']} | {$binding['port']}",
        $options['published_bindings'] ?? [],
    );
    file_put_contents("{$dir}/published-bindings", implode("\n", $publishedBindings));
    file_put_contents("{$dir}/network-exists", $networkExists ? '1' : '0');
    file_put_contents("{$dir}/valkey-probe.php", '');
    file_put_contents("{$dir}/self-contained-image", $selfContainedImage ? 'true' : 'false');
    file_put_contents("{$dir}/source-hash", $sourceHash);
    file_put_contents("{$dir}/source.tar", '');

    file_put_contents("{$dir}/docker", <<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        printf 'docker %s\n' "$*" >>"$dir/calls.log"

        case "$*" in
            'network inspect orbit-network')
                [ "$(cat "$dir/network-exists")" = 1 ]
                ;;
            'network create --label orbit.managed=true --label orbit.network.kind=runtime orbit-network')
                printf 1 >"$dir/network-exists"
                ;;
            'container inspect orbit-websocket-app-dev-1')
                [ "$(cat "$dir/container-exists")" = 1 ]
                ;;
            'container inspect --format {{json .}} orbit-websocket-app-dev-1')
                if [ "$(cat "$dir/container-exists")" != 1 ]; then
                    printf 'Error: No such container' >&2
                    exit 1
                fi
                printf '{"Image":"%s","Config":{"Labels":{"orbit.websocket.spec_hash":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}},"State":{"Running":%s}}' "$(cat "$dir/container-image-id")" "$(cat "$dir/container-running")"
                ;;
            'image inspect --format {{.Id}} orbit-reverb:current')
                if [ "$(cat "$dir/image-inspect-fails")" = 1 ]; then
                    printf 'Error: No such image' >&2
                    exit 1
                fi
                cat "$dir/current-image-id"
                ;;
            'rm -f orbit-websocket-app-dev-1')
                printf 0 >"$dir/container-exists"
                ;;
            'container inspect --format {{.State.Running}} orbit-websocket-app-dev-1')
                cat "$dir/container-running"
                ;;
            'container inspect --format {{range .Config.Env}}{{println .}}{{end}} orbit-websocket-app-dev-1')
                host="$(cat "$dir/env-host")"
                [ -z "$host" ] || printf 'REVERB_SERVER_HOST=%s\n' "$host"
                ;;
            'container inspect --format {{range .Config.Cmd}}{{print . " "}}{{end}} orbit-websocket-app-dev-1')
                cat "$dir/cmd"
                ;;
            'container inspect --format {{with (index .NetworkSettings.Ports "8080/tcp")}}{{range .}}{{println .HostIp "|" .HostPort}}{{end}}{{end}} orbit-websocket-app-dev-1')
                cat "$dir/published-bindings"
                ;;
            'exec -i orbit-websocket-app-dev-1 php')
                cat >"$dir/valkey-probe.php"
                ;;
            'restart orbit-websocket-app-dev-1')
                ;;
            run*'orbit-websocket-app-dev-1'*)
                printf 1 >"$dir/container-exists"
                ;;
            *)
                cat "$dir/self-contained-image"
                ;;
        esac
        BASH);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    file_put_contents("{$dir}/curl", <<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        printf 'curl %s\n' "$*" >>"$dir/calls.log"
        target=''
        while [ "$#" -gt 0 ]; do
            if [ "$1" = '--output' ]; then
                target="$2"
                break
            fi
            shift
        done
        [ -n "$target" ] || exit 1
        cp "$dir/image.tar" "$target"
        BASH);
    chmod(filename: "{$dir}/curl", permissions: 0o755);

    file_put_contents("{$dir}/tar", <<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        printf 'tar %s\n' "$*" >>"$dir/calls.log"
        cat "$dir/image-manifest.json"
        BASH);
    chmod(filename: "{$dir}/tar", permissions: 0o755);

    file_put_contents("{$dir}/sudo", <<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        source_hash="$(cat "$dir/source-hash")"
        printf 'sudo %s\n' "$*" >>"$dir/calls.log"

        case "$*" in
            'test -f /etc/orbit/websocket/app.key')
                [ "$(cat "$dir/app-key-exists")" = 1 ]
                ;;
            'test -f /etc/orbit/websocket/apps.php')
                [ -s "$dir/apps.php" ]
                ;;
            'test -f /opt/orbit/websocket/shared/.env')
                [ -f "$dir/shared.env" ]
                ;;
            "test -f /opt/orbit/websocket/releases/$source_hash/vendor/autoload.php")
                exit 1
                ;;
            'cat /etc/orbit/websocket/app.key')
                cat "$dir/app-key"
                ;;
            "cat /opt/orbit/websocket/releases/$source_hash/.orbit-websocket-source-hash")
                exit 1
                ;;
            'tee /etc/orbit/websocket/app.key')
                cat >"$dir/app-key"
                printf 1 >"$dir/app-key-exists"
                ;;
            'tee /etc/orbit/websocket/apps.php')
                cat >"$dir/apps.php"
                ;;
            'tee /opt/orbit/websocket/shared/.env')
                cat >"$dir/shared.env"
                ;;
            "tee /opt/orbit/websocket/releases/$source_hash/.orbit-websocket-source-hash")
                cat >"$dir/installed-source-hash"
                ;;
            "tar -xf - -C /opt/orbit/websocket/releases/$source_hash")
                cat >"$dir/source.tar"
                ;;
        esac
        BASH);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    file_put_contents("{$dir}/composer", <<<'BASH'
        #!/usr/bin/env bash
        dir="$(cd "$(dirname "$0")" && pwd)"
        printf 'composer %s\n' "$*" >>"$dir/calls.log"
        BASH);
    chmod(filename: "{$dir}/composer", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

/**
 * @param  array{image_archive_tags?: list<string>}  $options
 */
function websocket_runtime_image_manifest(array $options): string
{
    return json_encode([[
        'Config' => 'config.json',
        'RepoTags' => $options['image_archive_tags'] ?? [],
        'Layers' => ['layer.tar'],
    ]], JSON_THROW_ON_ERROR);
}

/**
 * @param  array{image_archive?: string}  $options
 */
function websocket_runtime_image_archive(array $options): string
{
    return $options['image_archive'] ?? '';
}

function delete_websocket_runtime_fake_bin(string $path): void
{
    foreach ([
        'docker',
        'curl',
        'sudo',
        'calls.log',
        'app-key',
        'app-key-exists',
        'apps.php',
        'cmd',
        'composer',
        'container-exists',
        'container-running',
        'container-image-id',
        'current-image-id',
        'image-inspect-fails',
        'env-host',
        'installed-source-hash',
        'image.tar',
        'image-manifest.json',
        'network-exists',
        'published-bindings',
        'valkey-probe.php',
        'self-contained-image',
        'shared.env',
        'source-hash',
        'source.tar',
        'tar',
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
