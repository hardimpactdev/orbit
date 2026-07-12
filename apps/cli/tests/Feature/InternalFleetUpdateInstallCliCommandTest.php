<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal fleet update install cli command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        $path = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '';

        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    });

    it('rejects a missing operation token before reading the install payload', function (): void {
        [$exitCode, $output] = run_internal_fleet_update_install_cli_command([
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('installs a downloaded CLI artifact into typed local paths', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);

        expect($exitCode)
            ->toBe(0, $output)
            ->and($data)
            ->toMatchArray([
                'installed' => true,
                'bin_path' => "{$workspace}/bin/orbit",
                'install_root' => "{$workspace}/install-root",
                'role_images' => [],
            ])
            ->and(is_link("{$workspace}/bin/orbit"))
            ->toBeTrue()
            ->and(fleet_update_install_cli_sha256(fleet_update_install_cli_binary_path($workspace, $sha256)))
            ->toBe($sha256)
            ->and(shell_exec(escapeshellarg("{$workspace}/bin/orbit").' --version --local'))
            ->toBe("Orbit 9.9.9\n");
    });

    it('installs from a hash-verified payload file', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $payload = json_encode([
            'artifact_url' => "file://{$artifactPath}",
            'sha256' => $sha256,
            'install_root' => "{$workspace}/install-root",
            'bin_path' => "{$workspace}/bin/orbit",
            'shared_binary_path' => null,
            'role_images' => [],
        ], JSON_THROW_ON_ERROR);
        $payloadFile = "{$workspace}/payload.json";

        file_put_contents($payloadFile, $payload);

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command([
            '--operation-token' => fleet_update_install_cli_signed_operation_token(),
            '--payload-file' => $payloadFile,
            '--payload-sha256' => hash('sha256', $payload),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0, $output)
            ->and(fleet_update_install_cli_success_data($output))
            ->toMatchArray([
                'installed' => true,
                'bin_path' => "{$workspace}/bin/orbit",
                'install_root' => "{$workspace}/install-root",
            ]);
    });

    it('rejects a payload file when its hash does not match', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $payloadFile = "{$workspace}/payload.json";

        file_put_contents($payloadFile, '{"artifact_url":"file:///tmp/orbit"}');

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command([
            '--operation-token' => fleet_update_install_cli_signed_operation_token(),
            '--payload-file' => $payloadFile,
            '--payload-sha256' => str_repeat('0', 64),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('validation_failed', 'Fleet update CLI install payload is invalid.'));
    });

    it('retries transient curl failures while downloading artifacts', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $fakeCurlBin = make_fleet_update_install_cli_fake_transient_curl_bin($workspace, $artifactPath);
        $path = $fakeCurlBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');

        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => 'https://artifacts.test/orbit',
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        PHPUnit\Framework\Assert::assertSame(0, $exitCode, $output);

        expect(file("{$workspace}/curl-attempts.log", FILE_IGNORE_NEW_LINES))
            ->toHaveCount(2)
            ->and(fleet_update_install_cli_success_data($output)['stdout'] ?? '')
            ->toContain('download_retry attempt=2')
            ->and(fleet_update_install_cli_sha256(fleet_update_install_cli_binary_path($workspace, $sha256)))
            ->toBe($sha256);
    });

    it('installs an optional Orbit Agent artifact into the requested binary path', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $agentSha256 = fleet_update_install_cli_sha256($agentArtifactPath);

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'agent_artifact' => [
                    'artifact_url' => "file://{$agentArtifactPath}",
                    'sha256' => $agentSha256,
                    'bin_path' => "{$workspace}/bin/orbit-agent",
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'agent_installed' => true,
                'agent_bin_path' => "{$workspace}/bin/orbit-agent",
            ])
            ->and(fleet_update_install_cli_sha256("{$workspace}/bin/orbit-agent"))
            ->toBe($agentSha256)
            ->and($data['stdout'] ?? '')
            ->toContain('download_agent')
            ->toContain('install_agent')
            ->toContain('verify_agent');
    });

    it('refreshes the generic shared binary link when installing a versioned shared CLI artifact', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $shaPrefix = substr($sha256, offset: 0, length: 12);
        $sharedBinaryPath = "{$workspace}/shared/orbit-binary-{$shaPrefix}";

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => $sharedBinaryPath,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );
        $genericSharedBinaryPath = "{$workspace}/shared/orbit-binary";

        expect($exitCode)
            ->toBe(0)
            ->and(fleet_update_install_cli_success_data($output)['stdout'] ?? '')
            ->toContain('install_cli')
            ->and(is_link($genericSharedBinaryPath))
            ->toBeTrue()
            ->and(readlink($genericSharedBinaryPath))
            ->toBe($sharedBinaryPath)
            ->and(fleet_update_install_cli_sha256(realpath($genericSharedBinaryPath) ?: $genericSharedBinaryPath))
            ->toBe($sha256);
    });

    it('schedules a deferred Orbit Agent restart through systemd-run when available', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $systemdBin = make_fleet_update_install_cli_fake_systemd_bin($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $agentSha256 = fleet_update_install_cli_sha256($agentArtifactPath);
        $path = $systemdBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');

        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'agent_artifact' => [
                    'artifact_url' => "file://{$agentArtifactPath}",
                    'sha256' => $agentSha256,
                    'bin_path' => "{$workspace}/bin/orbit-agent",
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);
        $calls = file_get_contents("{$workspace}/systemd-calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($data['stdout'] ?? '')
            ->toContain('probe_agent_unit')
            ->toContain('schedule_agent_restart')
            ->and($calls)
            ->toContain('systemd-run')
            ->toContain('--on-active=5s')
            ->toContain('systemctl restart orbit-agent');
    });

    it('restarts an unmanaged Orbit Agent listener when no service unit is present', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $agentRuntimeBin = make_fleet_update_install_cli_fake_unmanaged_agent_bin($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $agentConfigPath = "{$workspace}/agent.toml";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $agentSha256 = fleet_update_install_cli_sha256($agentArtifactPath);
        $path = $agentRuntimeBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');
        $originalAgentConfig = getenv('ORBIT_AGENT_CONFIG');
        $originalAgentHttpBind = getenv('ORBIT_AGENT_HTTP_BIND');
        $originalAgentLogPath = getenv('ORBIT_AGENT_LOG_PATH');

        putenv("PATH={$path}");
        putenv("ORBIT_AGENT_CONFIG={$agentConfigPath}");
        putenv('ORBIT_AGENT_HTTP_BIND=10.6.0.2:9477');
        putenv("ORBIT_AGENT_LOG_PATH={$workspace}/agent.log");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        try {
            [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
                [
                    '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                    '--json' => true,
                ],
                stdin: json_encode([
                    'artifact_url' => "file://{$artifactPath}",
                    'sha256' => $sha256,
                    'install_root' => "{$workspace}/install-root",
                    'bin_path' => "{$workspace}/bin/orbit",
                    'shared_binary_path' => null,
                    'agent_artifact' => [
                        'artifact_url' => "file://{$agentArtifactPath}",
                        'sha256' => $agentSha256,
                        'bin_path' => "{$workspace}/bin/orbit-agent",
                    ],
                    'role_images' => [],
                ], JSON_THROW_ON_ERROR),
            );
        } finally {
            fleet_update_install_cli_restore_env_var('ORBIT_AGENT_CONFIG', $originalAgentConfig);
            fleet_update_install_cli_restore_env_var('ORBIT_AGENT_HTTP_BIND', $originalAgentHttpBind);
            fleet_update_install_cli_restore_env_var('ORBIT_AGENT_LOG_PATH', $originalAgentLogPath);
        }

        $data = fleet_update_install_cli_success_data($output);
        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';
        $calls = file_get_contents("{$workspace}/agent-runtime-calls.log");

        if (! is_string($calls)) {
            $calls = '';
        }

        expect($exitCode)->toBe(0);
        expect($stdout)->toContain('restart_agent_unmanaged')->toContain('start_agent_unmanaged');
        expect(str_contains($stdout, 'skip_agent_restart_no_unit'))->toBeFalse();
        expect($calls)->toContain('pgrep -f '.$workspace.'/bin/orbit-agent')->toContain('ps -p 4242 -o command=');
    });

    it('keeps cli installs successful when role image pre-pulls are unavailable', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $pathWithoutDocker = make_fleet_update_install_cli_path_without_docker($workspace);

        putenv("PATH={$pathWithoutDocker}");
        $_ENV['PATH'] = $pathWithoutDocker;
        $_SERVER['PATH'] = $pathWithoutDocker;

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => ['ghcr.io/hardimpactdev/orbit-nonexistent-image:missing'],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);

        PHPUnit\Framework\Assert::assertSame(0, $exitCode, $output);

        expect($data['stdout'] ?? '')
            ->toContain('skip_required_image')
            ->and(fleet_update_install_cli_sha256(fleet_update_install_cli_binary_path($workspace, $sha256)))
            ->toBe($sha256);
    });
});

describe('systemd Orbit Agent adoption during fleet update install', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        $path = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '';

        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    });

    it('adopts a missing systemd Orbit Agent service from the install payload', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $systemdBin = make_fleet_update_install_cli_fake_missing_agent_systemd_bin($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $agentConfigPath = "{$workspace}/agent.toml";
        $agentCaPath = "{$workspace}/ca/root.crt";
        $agentCaPem = "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $agentSha256 = fleet_update_install_cli_sha256($agentArtifactPath);
        $path = $systemdBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');

        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'agent_artifact' => [
                    'artifact_url' => "file://{$agentArtifactPath}",
                    'sha256' => $agentSha256,
                    'bin_path' => "{$workspace}/bin/orbit-agent",
                ],
                'agent_service' => [
                    'unit_name' => 'orbit-agent',
                    'exec_start' => "{$workspace}/bin/orbit-agent",
                    'config_path' => $agentConfigPath,
                    'config' => implode("\n", [
                        'gateway_url = "https://10.6.0.1"',
                        'node_name = "app-1"',
                        'platform = "ubuntu_24-04"',
                        'managed = true',
                        'wireguard_address = "10.6.0.2"',
                        '',
                    ]),
                    'ca_path' => $agentCaPath,
                    'ca_pem' => $agentCaPem,
                    'http_bind' => '10.6.0.2:9477',
                    'user' => 'orbit',
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        $data = fleet_update_install_cli_success_data($output);
        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';
        $calls = file_get_contents("{$workspace}/missing-systemd-calls.log");
        $unit = file_get_contents("{$workspace}/adopted-orbit-agent.service");
        $config = file_get_contents($agentConfigPath);
        $caPem = file_get_contents($agentCaPath);

        expect($exitCode)->toBe(0);
        expect($stdout)
            ->toContain('probe_agent_unit')
            ->toContain('kill_agent_port_owner')
            ->toContain('adopt_agent_unit');
        expect(str_contains($stdout, 'skip_agent_restart_no_unit'))->toBeFalse();
        expect($calls)
            ->toContain('systemctl status orbit-agent')
            ->toContain('systemctl is-enabled orbit-agent')
            ->toContain('install -m 0644')
            ->toContain('/etc/systemd/system/orbit-agent.service')
            ->toContain('systemctl daemon-reload')
            ->toContain('systemctl enable orbit-agent.service')
            ->toContain('systemctl restart orbit-agent.service')
            ->toContain('ss -ltnp');
        expect($unit)
            ->toContain('User=orbit')
            ->toContain("Environment=ORBIT_AGENT_CONFIG={$agentConfigPath}")
            ->toContain('Environment=ORBIT_AGENT_HTTP_BIND=10.6.0.2:9477')
            ->toContain("ExecStart={$workspace}/bin/orbit-agent");
        expect($config)
            ->toContain('platform = "ubuntu_24-04"')
            ->toContain('managed = true')
            ->toContain('wireguard_address = "10.6.0.2"');
        expect($caPem)->toBe($agentCaPem);
    });

    it('preserves live Agent trust files and does not restart when staging fails', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $failureBin = make_fleet_update_install_cli_failing_agent_config_bin($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $agentConfigPath = "{$workspace}/agent.toml";
        $agentCaPath = "{$workspace}/ca/root.crt";
        $oldConfig = "node_name = \"old-node\"\n";
        $oldCaPem = "-----BEGIN CERTIFICATE-----\nb2xk\n-----END CERTIFICATE-----\n";
        $newCaPem = "-----BEGIN CERTIFICATE-----\nbmV3\n-----END CERTIFICATE-----\n";

        mkdir(dirname($agentCaPath), recursive: true);
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        file_put_contents(filename: $agentConfigPath, data: $oldConfig);
        file_put_contents(filename: $agentCaPath, data: $oldCaPem);
        chmod(filename: $agentArtifactPath, permissions: 0o755);

        $path = $failureBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');
        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => fleet_update_install_cli_sha256($artifactPath),
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'agent_artifact' => [
                    'artifact_url' => "file://{$agentArtifactPath}",
                    'sha256' => fleet_update_install_cli_sha256($agentArtifactPath),
                    'bin_path' => "{$workspace}/bin/orbit-agent",
                ],
                'agent_service' => [
                    'unit_name' => 'orbit-agent',
                    'exec_start' => "{$workspace}/bin/orbit-agent",
                    'config_path' => $agentConfigPath,
                    'config' => "node_name = \"new-node\"\n",
                    'ca_path' => $agentCaPath,
                    'ca_pem' => $newCaPem,
                    'http_bind' => '10.6.0.2:9477',
                    'user' => 'orbit',
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        $calls = file_get_contents("{$workspace}/agent-config-failure-calls.log");

        expect($exitCode)
            ->toBe(1, $output)
            ->and(file_get_contents($agentConfigPath))
            ->toBe($oldConfig)
            ->and(file_get_contents($agentCaPath))
            ->toBe($oldCaPem)
            ->and($calls)
            ->not->toContain('systemctl');
    });
});

describe('macos Orbit Agent launchd restart during fleet update install', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        fleet_update_install_cli_store_environment();
    });

    afterEach(function (): void {
        fleet_update_install_cli_restore_environment();
    });

    it('restarts a loaded launchd service after installing an agent artifact', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $launchctlBin = make_fleet_update_install_cli_fake_launchctl_bin($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $agentSha256 = fleet_update_install_cli_sha256($agentArtifactPath);
        $path = $launchctlBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');

        putenv("PATH={$path}");
        putenv("ORBIT_AGENT_LAUNCHCTL_BIN={$launchctlBin}/launchctl");
        $_ENV['PATH'] = $path;
        $_ENV['ORBIT_AGENT_LAUNCHCTL_BIN'] = "{$launchctlBin}/launchctl";
        $_SERVER['PATH'] = $path;

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'agent_artifact' => [
                    'artifact_url' => "file://{$agentArtifactPath}",
                    'sha256' => $agentSha256,
                    'bin_path' => "{$workspace}/bin/orbit-agent",
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);
        $calls = file_get_contents("{$workspace}/launchctl-calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($data['stdout'] ?? '')
            ->toContain('restart_agent_launchd')
            ->and($calls)
            ->toContain('launchctl print gui/')
            ->toContain('/dev.orbit.agent')
            ->toContain('launchctl kickstart -k gui/');
    });
});

describe('internal fleet update install cli launcher isolation', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        $path = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '';

        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    });

    it('does not relink a path-resolved orbit launcher outside the payload paths', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $shadowLauncher = make_fleet_update_install_cli_shadow_launcher($workspace);
        $path = dirname($shadowLauncher).PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');

        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        [$exitCode] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => $sha256,
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and(is_link($shadowLauncher))
            ->toBeFalse()
            ->and(file_get_contents($shadowLauncher))
            ->toContain('Orbit shadow');
    });
});

function fleet_update_install_cli_signed_operation_token(
    string $id = 'fleet-update-install-cli',
    string $node = 'app-dev',
    string $command = 'internal:fleet-update:install-cli',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: fleet_update_install_cli_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function fleet_update_install_cli_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function fleet_update_install_cli_sha256(string $path): string
{
    $hash = hash_file('sha256', $path);

    if (! is_string($hash)) {
        throw new RuntimeException("Could not hash [{$path}].");
    }

    return $hash;
}

function fleet_update_install_cli_store_environment(): void
{
    $originalPath = getenv('PATH');
    $originalLaunchctlBin = getenv('ORBIT_AGENT_LAUNCHCTL_BIN');
    $originalLaunchdLabel = getenv('ORBIT_AGENT_LAUNCHD_LABEL');

    $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_LAUNCHCTL_BIN'] = $originalLaunchctlBin === false
        ? ''
        : $originalLaunchctlBin;
    $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_LAUNCHD_LABEL'] = $originalLaunchdLabel === false
        ? ''
        : $originalLaunchdLabel;
}

function fleet_update_install_cli_restore_environment(): void
{
    $path = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '';
    $launchctlBin = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_LAUNCHCTL_BIN'] ?? '';
    $launchdLabel = $_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_LAUNCHD_LABEL'] ?? '';

    putenv('PATH='.$path);
    $_ENV['PATH'] = $path;
    $_SERVER['PATH'] = $path;

    putenv($launchctlBin === '' ? 'ORBIT_AGENT_LAUNCHCTL_BIN' : 'ORBIT_AGENT_LAUNCHCTL_BIN='.$launchctlBin);
    putenv($launchdLabel === '' ? 'ORBIT_AGENT_LAUNCHD_LABEL' : 'ORBIT_AGENT_LAUNCHD_LABEL='.$launchdLabel);
    $_ENV['ORBIT_AGENT_LAUNCHCTL_BIN'] = $launchctlBin;
    $_ENV['ORBIT_AGENT_LAUNCHD_LABEL'] = $launchdLabel;
}

function fleet_update_install_cli_restore_env_var(string $key, string|false $value): void
{
    if ($value === false) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_fleet_update_install_cli_command(array $parameters, string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    /** @var Command|null $command */
    $command = Artisan::all()['internal:fleet-update:install-cli'] ?? null;

    expect($command)->toBeInstanceOf(Command::class);

    $exitCode = $command instanceof Command ? $command->run($input, $output) : 1;

    return [$exitCode, trim($output->fetch())];
}

/**
 * @return array<string, mixed>
 */
function fleet_update_install_cli_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

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

function make_fleet_update_install_cli_workspace(): string
{
    $workspace = sys_get_temp_dir().'/orbit-fleet-update-install-cli-'.bin2hex(random_bytes(8));

    mkdir("{$workspace}/artifact", recursive: true);
    mkdir("{$workspace}/bin", recursive: true);
    $artifact = "{$workspace}/artifact/orbit";

    file_put_contents($artifact, <<<'SH'
        #!/usr/bin/env sh
        echo "Orbit 9.9.9"
        SH);
    chmod(filename: $artifact, permissions: 0o755);

    return $workspace;
}

function make_fleet_update_install_cli_path_without_docker(string $workspace): string
{
    $bin = "{$workspace}/path-bin";

    mkdir($bin, recursive: true);

    foreach ([
        'awk',
        'basename',
        'bash',
        'cp',
        'dirname',
        'install',
        'ln',
        'mktemp',
        'mv',
        'readlink',
        'rm',
        'sh',
    ] as $command) {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($command)));

        if ($path !== '') {
            symlink($path, "{$bin}/{$command}");
        }
    }

    foreach (['sha256sum', 'shasum'] as $command) {
        $path = trim((string) shell_exec('command -v '.escapeshellarg($command)));

        if ($path !== '') {
            symlink($path, "{$bin}/{$command}");

            break;
        }
    }

    return $bin;
}

function make_fleet_update_install_cli_fake_transient_curl_bin(string $workspace, string $artifactPath): string
{
    $bin = "{$workspace}/curl-bin";
    $attemptsPath = "{$workspace}/curl-attempts.log";

    mkdir($bin, recursive: true);
    file_put_contents($attemptsPath, '');

    $artifactArgument = escapeshellarg($artifactPath);
    $attemptsArgument = escapeshellarg($attemptsPath);

    file_put_contents("{$bin}/curl", <<<SH
        #!/usr/bin/env sh
        artifact_path={$artifactArgument}
        attempts_path={$attemptsArgument}
        target=""

        while [ "\$#" -gt 0 ]; do
          if [ "\$1" = "-o" ]; then
            shift
            target="\$1"
          fi

          shift
        done

        attempts=\$(wc -l < "\$attempts_path" | tr -d ' ')
        echo "curl \$*" >> "\$attempts_path"

        if [ "\$attempts" -eq 0 ]; then
          echo "curl: (22) The requested URL returned error: 503" >&2
          exit 22
        fi

        cp "\$artifact_path" "\$target"
        SH);
    chmod(filename: "{$bin}/curl", permissions: 0o755);

    return $bin;
}

function make_fleet_update_install_cli_shadow_launcher(string $workspace): string
{
    $bin = "{$workspace}/shadow-bin";

    mkdir($bin, recursive: true);

    $launcher = "{$bin}/orbit";

    file_put_contents($launcher, <<<'SH'
        #!/usr/bin/env sh
        echo "Orbit shadow"
        SH);
    chmod(filename: $launcher, permissions: 0o755);

    return $launcher;
}

function make_fleet_update_install_cli_fake_systemd_bin(string $workspace): string
{
    $bin = "{$workspace}/systemd-bin";
    $log = "{$workspace}/systemd-calls.log";

    mkdir($bin, recursive: true);
    file_put_contents($log, '');

    file_put_contents("{$bin}/systemctl", <<<SH
        #!/usr/bin/env sh
        echo "systemctl \$*" >> {$log}
        if [ "\$1" = "status" ] || [ "\$1" = "is-enabled" ]; then
          exit 0
        fi
        exit 0
        SH);
    chmod(filename: "{$bin}/systemctl", permissions: 0o755);

    file_put_contents("{$bin}/systemd-run", <<<SH
        #!/usr/bin/env sh
        echo "systemd-run \$*" >> {$log}
        exit 0
        SH);
    chmod(filename: "{$bin}/systemd-run", permissions: 0o755);

    return $bin;
}

function make_fleet_update_install_cli_fake_missing_agent_systemd_bin(string $workspace): string
{
    $bin = "{$workspace}/missing-systemd-bin";
    $log = "{$workspace}/missing-systemd-calls.log";
    $unit = "{$workspace}/adopted-orbit-agent.service";
    $realInstall = trim((string) shell_exec('command -v install'));

    mkdir($bin, recursive: true);
    file_put_contents($log, '');

    file_put_contents("{$bin}/systemctl", <<<SH
        #!/usr/bin/env sh
        echo "systemctl \$*" >> {$log}
        if [ "\$1" = "status" ] || [ "\$1" = "is-enabled" ]; then
          exit 1
        fi
        exit 0
        SH);
    chmod(filename: "{$bin}/systemctl", permissions: 0o755);

    file_put_contents("{$bin}/install", <<<SH
        #!/usr/bin/env sh
        echo "install \$*" >> {$log}
        last=""
        for arg in "\$@"; do
          last="\$arg"
        done
        if [ "\$last" = "/etc/systemd/system/orbit-agent.service" ]; then
          cp "\$3" {$unit}
          exit 0
        fi
        exec {$realInstall} "\$@"
        SH);
    chmod(filename: "{$bin}/install", permissions: 0o755);

    file_put_contents("{$bin}/ss", <<<SH
        #!/usr/bin/env sh
        echo "ss \$*" >> {$log}
        echo 'LISTEN 0 128 10.6.0.2:9477 0.0.0.0:* users:(("orbit-agent",pid=5151,fd=8))'
        exit 0
        SH);
    chmod(filename: "{$bin}/ss", permissions: 0o755);

    file_put_contents("{$bin}/sleep", <<<'SH'
        #!/usr/bin/env sh
        exit 0
        SH);
    chmod(filename: "{$bin}/sleep", permissions: 0o755);

    return $bin;
}

function make_fleet_update_install_cli_failing_agent_config_bin(string $workspace): string
{
    $bin = "{$workspace}/agent-config-failure-bin";
    $log = "{$workspace}/agent-config-failure-calls.log";
    $configPath = "{$workspace}/agent.toml";
    $realInstall = trim((string) shell_exec('command -v install'));

    mkdir($bin, recursive: true);
    file_put_contents($log, '');
    file_put_contents("{$bin}/install", <<<SH
        #!/usr/bin/env sh
        echo "install \$*" >> {$log}
        last=""
        for arg in "\$@"; do
          last="\$arg"
        done
        case "\$last" in
          {$configPath}|*/.orbit-agent-config.*)
            printf '%s' 'partial' > "\$last"
            exit 42
            ;;
        esac
        exec {$realInstall} "\$@"
        SH);
    chmod(filename: "{$bin}/install", permissions: 0o755);

    file_put_contents("{$bin}/systemctl", <<<SH
        #!/usr/bin/env sh
        echo "systemctl \$*" >> {$log}
        exit 0
        SH);
    chmod(filename: "{$bin}/systemctl", permissions: 0o755);

    return $bin;
}

function make_fleet_update_install_cli_fake_unmanaged_agent_bin(string $workspace): string
{
    $bin = "{$workspace}/agent-runtime-bin";
    $log = "{$workspace}/agent-runtime-calls.log";

    mkdir($bin, recursive: true);
    file_put_contents($log, '');

    foreach (['systemctl', 'launchctl'] as $command) {
        file_put_contents("{$bin}/{$command}", <<<SH
            #!/usr/bin/env sh
            echo "{$command} \$*" >> {$log}
            exit 1
            SH);
        chmod(filename: "{$bin}/{$command}", permissions: 0o755);
    }

    file_put_contents("{$bin}/pgrep", <<<SH
        #!/usr/bin/env sh
        echo "pgrep \$*" >> {$log}
        echo 4242
        exit 0
        SH);
    chmod(filename: "{$bin}/pgrep", permissions: 0o755);

    file_put_contents("{$bin}/ps", <<<SH
        #!/usr/bin/env sh
        echo "ps \$*" >> {$log}
        if [ "\$*" = "-p 4242 -o command=" ]; then
            echo "{$workspace}/bin/orbit-agent --serve"
            exit 0
        fi
        exit 1
        SH);
    chmod(filename: "{$bin}/ps", permissions: 0o755);

    file_put_contents("{$bin}/nohup", <<<SH
        #!/usr/bin/env sh
        echo "nohup \$*" >> {$log}
        exit 0
        SH);
    chmod(filename: "{$bin}/nohup", permissions: 0o755);

    file_put_contents("{$bin}/sleep", <<<'SH'
        #!/usr/bin/env sh
        exit 0
        SH);
    chmod(filename: "{$bin}/sleep", permissions: 0o755);

    return $bin;
}

function make_fleet_update_install_cli_fake_launchctl_bin(string $workspace): string
{
    $bin = "{$workspace}/launchctl-bin";
    $log = "{$workspace}/launchctl-calls.log";

    mkdir($bin, recursive: true);
    file_put_contents($log, '');

    file_put_contents("{$bin}/systemctl", <<<'SH'
        #!/usr/bin/env sh
        exit 1
        SH);
    chmod(filename: "{$bin}/systemctl", permissions: 0o755);

    file_put_contents("{$bin}/launchctl", <<<SH
        #!/usr/bin/env sh
        echo "launchctl \$*" >> {$log}
        if [ "\$1" = "print" ]; then
          case "\$2" in
            gui/*/dev.orbit.agent) exit 0 ;;
          esac
        fi
        if [ "\$1" = "kickstart" ]; then
          exit 0
        fi
        exit 1
        SH);
    chmod(filename: "{$bin}/launchctl", permissions: 0o755);

    return $bin;
}

function fleet_update_install_cli_binary_path(string $workspace, string $sha256): string
{
    return "{$workspace}/install-root/bin/orbit-binary-".substr($sha256, offset: 0, length: 12);
}
