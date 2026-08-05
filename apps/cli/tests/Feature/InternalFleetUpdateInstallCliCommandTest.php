<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Isolate every suite in this file from the runner's real install metadata path.
// Successful installs write InstallMetadataStore output; without this override they
// would pollute $HOME/.config/orbit/install.json (or a pre-set ORBIT_INSTALL_METADATA_PATH).
beforeEach(function (): void {
    $this->previousOrbitInstallMetadataPath = getenv('ORBIT_INSTALL_METADATA_PATH');

    $metadataDir = sys_get_temp_dir().'/orbit-fleet-install-metadata-'.bin2hex(random_bytes(8));
    mkdir($metadataDir, permissions: 0o700, recursive: true);
    $metadataPath = "{$metadataDir}/install.json";

    $this->orbitFleetInstallMetadataDir = $metadataDir;
    $this->orbitFleetInstallMetadataPath = $metadataPath;

    putenv("ORBIT_INSTALL_METADATA_PATH={$metadataPath}");
    $_ENV['ORBIT_INSTALL_METADATA_PATH'] = $metadataPath;
    $_SERVER['ORBIT_INSTALL_METADATA_PATH'] = $metadataPath;
});

afterEach(function (): void {
    fleet_update_install_cli_restore_env_var(
        'ORBIT_INSTALL_METADATA_PATH',
        $this->previousOrbitInstallMetadataPath,
    );

    $metadataDir = $this->orbitFleetInstallMetadataDir ?? null;

    if (! is_string($metadataDir) || $metadataDir === '' || ! is_dir($metadataDir)) {
        return;
    }

    foreach (glob($metadataDir.'/*') ?: [] as $path) {
        if (! is_file($path)) {
            continue;
        }

        unlink($path);
    }

    if (is_dir($metadataDir)) {
        rmdir($metadataDir);
    }
});

/** @mago-expect lint:cyclomatic-complexity */
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
        $metadataPath = (string) $this->orbitFleetInstallMetadataPath;

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
        $metadata = is_file($metadataPath)
            ? json_decode((string) file_get_contents($metadataPath), associative: true, flags: JSON_THROW_ON_ERROR)
            : null;

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
            ->toBe(fleet_update_install_cli_fake_version_output())
            ->and($metadata)
            ->toMatchArray([
                'schema_version' => 1,
                'version' => '9.9.9',
                'binary_path' => "{$workspace}/bin/orbit",
                'install_root' => "{$workspace}/install-root",
            ])
            ->and($metadata['installed_at'] ?? null)
            ->not->toBeNull();
    });

    it('does not create or alter install metadata when artifact hash verification fails', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $metadataPath = (string) $this->orbitFleetInstallMetadataPath;
        $seededMetadata =
            json_encode([
                'schema_version' => 1,
                'version' => '1.0.0',
                'released_at' => null,
                'installed_at' => '2020-01-01T00:00:00+00:00',
                'binary_path' => '/seeded/bin/orbit',
                'install_root' => '/seeded/install-root',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";

        file_put_contents($metadataPath, $seededMetadata);

        [$exitCode, $output] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => str_repeat('0', times: 64),
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1, $output)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
            ->toBe('fleet_update.cli_install_failed')
            ->and(file_get_contents($metadataPath))
            ->toBe($seededMetadata);

        unlink($metadataPath);

        [$createExitCode, $createOutput] = run_internal_fleet_update_install_cli_command(
            [
                '--operation-token' => fleet_update_install_cli_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'artifact_url' => "file://{$artifactPath}",
                'sha256' => str_repeat('0', times: 64),
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        expect($createExitCode)
            ->toBe(1, $createOutput)
            ->and(is_file($metadataPath))
            ->toBeFalse();
    });

    it('writes install metadata from structured version JSON ignoring earlier semver-like stdout', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $metadataPath = (string) $this->orbitFleetInstallMetadataPath;

        // Progress noise may contain dotted triples; only JSON version wins.
        file_put_contents($artifactPath, <<<'SH'
            #!/usr/bin/env sh
            echo "pulling ghcr.io/hardimpactdev/orbit-reverb:1.2.3@sha256:deadbeef"
            printf '%s\n' '{"success":{"data":{"version":"9.9.9","latest_version":"1.2.3","update_available":true,"released_at":null,"installed_at":null},"meta":[]}}'
            SH);
        chmod(filename: $artifactPath, permissions: 0o755);

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
        $metadata = is_file($metadataPath)
            ? json_decode((string) file_get_contents($metadataPath), associative: true, flags: JSON_THROW_ON_ERROR)
            : null;

        expect($exitCode)
            ->toBe(0, $output)
            ->and($metadata)
            ->toMatchArray([
                'schema_version' => 1,
                'version' => '9.9.9',
                'binary_path' => "{$workspace}/bin/orbit",
                'install_root' => "{$workspace}/install-root",
            ]);
    });

    it('fails when CLI install succeeds but version output is not structured JSON', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $metadataPath = (string) $this->orbitFleetInstallMetadataPath;

        // Human Version table only — install process exits 0 but metadata path fails closed.
        file_put_contents($artifactPath, <<<'SH'
            #!/usr/bin/env sh
            echo "Version       9.9.9"
            echo "Released at   unknown"
            echo "Installed at  unknown"
            SH);
        chmod(filename: $artifactPath, permissions: 0o755);

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

        expect($exitCode)
            ->toBe(1, $output)
            ->and(is_file($metadataPath))
            ->toBeFalse()
            ->and($output)
            ->toContain('fleet_update.cli_version_unstructured')
            ->toContain('structured JSON');
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
        $metadataPath = (string) $this->orbitFleetInstallMetadataPath;

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
            ->toBe(JsonEnvelope::failure('validation_failed', 'Fleet update CLI install payload is invalid.'))
            ->and(is_file($metadataPath))
            ->toBeFalse();
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

    it('installs required role images before scheduling a deferred Orbit Agent restart', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $systemdBin = make_fleet_update_install_cli_fake_systemd_bin($workspace);
        $dockerLog = "{$workspace}/docker.log";
        $dockerBin = make_fleet_update_install_cli_fake_docker_bin($workspace, $dockerLog);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $roleImageArchive = "{$workspace}/artifact/orbit-reverb.tar";
        $agentConfigPath = "{$workspace}/agent.toml";
        $agentCaPath = "{$workspace}/ca/root.crt";
        $agentCaPem = "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        file_put_contents(filename: $roleImageArchive, data: 'verified role image archive');
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $agentSha256 = fleet_update_install_cli_sha256($agentArtifactPath);
        $roleImage = 'ghcr.io/hardimpactdev/orbit-reverb:9.9.9@sha256:'.str_repeat('a', times: 64);
        $candidateRuntimeImage = 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-build@sha256:'
        .str_repeat(
            'b',
            times: 64,
        );
        $runtimeImage = 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm';
        $path =
            $systemdBin
            .PATH_SEPARATOR
            .$dockerBin
            .PATH_SEPARATOR
            .($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');

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
                    'config' => "node_name = \"app-1\"\n",
                    'ca_path' => $agentCaPath,
                    'ca_pem' => $agentCaPem,
                    'http_bind' => '10.6.0.2:9477',
                    'user' => 'orbit',
                ],
                'role_images' => [$roleImage, $candidateRuntimeImage],
                'role_image_artifacts' => [[
                    'image' => $roleImage,
                    'url' => "file://{$roleImageArchive}",
                    'sha256' => hash_file('sha256', $roleImageArchive),
                ]],
                'role_image_aliases' => [[
                    'source' => $candidateRuntimeImage,
                    'target' => $runtimeImage,
                ]],
            ], JSON_THROW_ON_ERROR),
        );
        $data = fleet_update_install_cli_success_data($output);
        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';
        $calls = file_get_contents("{$workspace}/systemd-calls.log");
        $unit = file_get_contents("{$workspace}/converged-orbit-agent.service");
        $runtimeBootScript = file_get_contents("{$workspace}/orbit-runtime-boot-converge");
        $runtimeBootUnit = file_get_contents("{$workspace}/orbit-runtime-boot-converge.service");

        expect($exitCode)
            ->toBe(0)
            ->and($stdout)
            ->toContain('probe_agent_unit')
            ->toContain('converge_agent_unit')
            ->toContain('load_required_image_artifacts')
            ->toContain('alias_required_images')
            ->toContain('schedule_agent_restart')
            ->and(strpos($stdout, 'load_required_image_artifacts'))
            ->toBeLessThan(strpos($stdout, 'schedule_agent_restart'))
            ->and($calls)
            ->toContain('systemd-run')
            ->toContain('--on-active=5s')
            ->toContain('systemctl restart orbit-agent')
            ->toContain('systemctl daemon-reload')
            ->toContain('systemctl enable orbit-runtime-boot-converge.service')
            ->and($unit)
            ->toContain("ExecStart={$workspace}/bin/orbit-agent")
            ->toContain("Environment=ORBIT_AGENT_CONFIG={$agentConfigPath}")
            ->toContain('After=network-online.target')
            ->not->toContain('wg-quick@')->and($runtimeBootScript)->toContain('managed_container_ids caddy')->toContain(
                'app-runtime workspace-runtime websocket-runtime',
            )->and($runtimeBootUnit)->toContain('After=docker.service network-online.target')->toContain(
                'Restart=on-failure',
            )
            ->not->toContain('wg-quick@');

        expect((string) file_get_contents($dockerLog))
            ->toContain("image inspect {$candidateRuntimeImage}")
            ->toContain("image tag sha256:orbit-test-image {$runtimeImage}")
            ->toContain("image inspect --format {{.Id}} {$runtimeImage}");
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

    it('loads a hash-verified role image archive before registry fallback', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $artifactPath = "{$workspace}/artifact/orbit";
        $sha256 = fleet_update_install_cli_sha256($artifactPath);
        $roleImageArchive = "{$workspace}/artifact/orbit-reverb.tar";
        file_put_contents($roleImageArchive, data: 'verified role image archive');
        $dockerLog = "{$workspace}/docker.log";
        $dockerPath = make_fleet_update_install_cli_offline_docker_bin($workspace, $dockerLog);
        $originalPath = $_SERVER['PATH'] ?? getenv('PATH');
        $candidateImage = 'ghcr.io/hardimpactdev/orbit-reverb:9.9.9-candidate@sha256:'.str_repeat('a', times: 64);
        $stableImage = 'ghcr.io/hardimpactdev/orbit-reverb:9.9.9';

        putenv('PATH='.$dockerPath.':'.(is_string($originalPath) ? $originalPath : ''));
        $_ENV['PATH'] = getenv('PATH');
        $_SERVER['PATH'] = getenv('PATH');

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
                'role_images' => [$candidateImage],
                'role_image_artifacts' => [[
                    'image' => $candidateImage,
                    'url' => "file://{$roleImageArchive}",
                    'sha256' => hash_file('sha256', $roleImageArchive),
                ]],
                'role_image_aliases' => [[
                    'source' => $candidateImage,
                    'target' => $stableImage,
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0, $output)
            ->and((string) file_get_contents($dockerLog))
            ->toContain('load --input')
            ->toContain('image inspect ghcr.io/hardimpactdev/orbit-reverb:9.9.9-candidate')
            ->toContain("image tag sha256:orbit-test-image {$stableImage}")
            ->toContain("pull {$candidateImage}");
    });
});

/**
 * @mago-expect lint:halstead
 */
describe('managed Orbit Agent service boundary during fleet update install', function (): void {
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

    it('fails closed when the managed systemd Orbit Agent service is missing', function (): void {
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

        /** @var array{error?: array{code?: string, meta?: array{stdout?: string, stderr?: string}}} $payload */
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $error = $payload['error'] ?? [];
        $meta = $error['meta'] ?? [];
        $calls = file_get_contents("{$workspace}/missing-systemd-calls.log");

        if (! is_string($calls)) {
            $calls = '';
        }

        expect($exitCode)
            ->toBe(1, $output)
            ->and($error['code'] ?? null)
            ->toBe('fleet_update.cli_install_failed')
            ->and($meta['stdout'] ?? '')
            ->toContain('probe_agent_unit')
            ->and($meta['stderr'] ?? '')
            ->toContain('agent_service_missing_bootstrap_required');
        expect($calls)
            ->toContain('systemctl status orbit-agent')
            ->toContain('systemctl is-enabled orbit-agent');
        expect(str_contains($calls, '/etc/systemd/system/orbit-agent.service'))->toBeFalse();
        expect(str_contains($calls, 'systemctl daemon-reload'))->toBeFalse();
        expect(str_contains($calls, 'systemctl enable'))->toBeFalse();
        expect(str_contains($calls, 'systemctl restart'))->toBeFalse();
        expect(str_contains($calls, 'ss -ltnp'))->toBeFalse();
        expect(file_exists("{$workspace}/unexpected-orbit-agent.service"))->toBeFalse();
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

    it('writes Agent config and CA when host php is unavailable', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $systemdBin = make_fleet_update_install_cli_fake_systemd_bin($workspace);
        $pathWithoutPhp = make_fleet_update_install_cli_path_without_php($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $agentConfigPath = "{$workspace}/agent.toml";
        $agentCaPath = "{$workspace}/ca/root.crt";
        $agentConfig = implode("\n", [
            'gateway_url = "https://10.6.0.1"',
            'node_name = "services-1"',
            'platform = "ubuntu_24-04"',
            'managed = true',
            'wireguard_address = "10.6.0.9"',
            '',
        ]);
        $agentCaPem = "-----BEGIN CERTIFICATE-----\nc2VydmljZXMx\n-----END CERTIFICATE-----\n";

        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);

        $path = $systemdBin.PATH_SEPARATOR.$pathWithoutPhp;
        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        expect(trim((string) shell_exec('command -v php || true')))->toBeEmpty();

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
                    'config' => $agentConfig,
                    'ca_path' => $agentCaPath,
                    'ca_pem' => $agentCaPem,
                    'http_bind' => '10.6.0.9:9477',
                    'user' => 'orbit',
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        $data = fleet_update_install_cli_success_data($output);
        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';

        expect($exitCode)
            ->toBe(0, $output)
            ->and($stdout)
            ->toContain('write_agent_config')
            ->toContain('schedule_agent_restart')
            ->and(file_get_contents($agentConfigPath))
            ->toBe($agentConfig)
            ->and(file_get_contents($agentCaPath))
            ->toBe($agentCaPem)
            ->and($stdout)
            ->not->toContain('php: command not found');
    });

    it('loads role image side effects when host php is unavailable', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $systemdBin = make_fleet_update_install_cli_fake_systemd_bin($workspace);
        $pathWithoutPhp = make_fleet_update_install_cli_path_without_php($workspace);
        $dockerLog = "{$workspace}/docker.log";
        $dockerBin = make_fleet_update_install_cli_fake_docker_bin($workspace, $dockerLog);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $roleImageArchive = "{$workspace}/artifact/orbit-reverb.tar";
        $agentConfigPath = "{$workspace}/agent.toml";
        $agentCaPath = "{$workspace}/ca/root.crt";
        $agentCaPem = "-----BEGIN CERTIFICATE-----\nc2VydmljZXMx\n-----END CERTIFICATE-----\n";
        $roleImage = 'ghcr.io/hardimpactdev/orbit-reverb:9.9.9@sha256:'.str_repeat('c', times: 64);
        $candidateRuntimeImage =
            'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-build@sha256:'
            .str_repeat('d', times: 64);
        $runtimeImage = 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm';

        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        file_put_contents(filename: $roleImageArchive, data: 'verified role image archive');
        chmod(filename: $agentArtifactPath, permissions: 0o755);

        $path = $systemdBin.PATH_SEPARATOR.$dockerBin.PATH_SEPARATOR.$pathWithoutPhp;
        putenv("PATH={$path}");
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        expect(trim((string) shell_exec('command -v php || true')))->toBeEmpty();

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
                    'config' => "node_name = \"services-1\"\n",
                    'ca_path' => $agentCaPath,
                    'ca_pem' => $agentCaPem,
                    'http_bind' => '10.6.0.9:9477',
                    'user' => 'orbit',
                ],
                'role_images' => [$roleImage, $candidateRuntimeImage],
                'role_image_artifacts' => [[
                    'image' => $roleImage,
                    'url' => "file://{$roleImageArchive}",
                    'sha256' => hash_file('sha256', $roleImageArchive),
                ]],
                'role_image_aliases' => [[
                    'source' => $candidateRuntimeImage,
                    'target' => $runtimeImage,
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        $data = fleet_update_install_cli_success_data($output);
        $stdout = is_string($data['stdout'] ?? null) ? $data['stdout'] : '';

        expect($exitCode)
            ->toBe(0, $output)
            ->and($stdout)
            ->toContain('write_agent_config')
            ->toContain('load_required_image_artifacts')
            ->toContain('alias_required_images')
            ->toContain('schedule_agent_restart')
            ->and((string) file_get_contents($dockerLog))
            ->toContain('load --input')
            ->toContain("image tag sha256:orbit-test-image {$runtimeImage}");
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

describe('Orbit Agent restart failure during fleet update install', function (): void {
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

    it('fails closed when an unmanaged Orbit Agent listener has no discoverable config', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $agentRuntimeBin = make_fleet_update_install_cli_fake_unmanaged_agent_bin($workspace);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        $home = "{$workspace}/home";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
        mkdir($home, recursive: true);

        $path = $agentRuntimeBin.PATH_SEPARATOR.($_ENV['ORBIT_FLEET_UPDATE_INSTALL_CLI_ORIGINAL_PATH'] ?? '');
        $originalAgentConfig = getenv('ORBIT_AGENT_CONFIG');
        $originalAgentHttpBind = getenv('ORBIT_AGENT_HTTP_BIND');
        $originalAgentLogPath = getenv('ORBIT_AGENT_LOG_PATH');
        $originalHome = getenv('HOME');

        putenv("PATH={$path}");
        putenv('ORBIT_AGENT_CONFIG');
        putenv('ORBIT_AGENT_HTTP_BIND');
        putenv('ORBIT_AGENT_LOG_PATH');
        putenv("HOME={$home}");
        unset(
            $_ENV['ORBIT_AGENT_CONFIG'],
            $_SERVER['ORBIT_AGENT_CONFIG'],
            $_ENV['ORBIT_AGENT_HTTP_BIND'],
            $_SERVER['ORBIT_AGENT_HTTP_BIND'],
            $_ENV['ORBIT_AGENT_LOG_PATH'],
            $_SERVER['ORBIT_AGENT_LOG_PATH'],
        );
        $_ENV['HOME'] = $home;
        $_SERVER['HOME'] = $home;
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
                    'sha256' => fleet_update_install_cli_sha256($artifactPath),
                    'install_root' => "{$workspace}/install-root",
                    'bin_path' => "{$workspace}/bin/orbit",
                    'shared_binary_path' => null,
                    'agent_artifact' => [
                        'artifact_url' => "file://{$agentArtifactPath}",
                        'sha256' => fleet_update_install_cli_sha256($agentArtifactPath),
                        'bin_path' => "{$workspace}/bin/orbit-agent",
                    ],
                    'role_images' => [],
                ], JSON_THROW_ON_ERROR),
            );
        } finally {
            fleet_update_install_cli_restore_env_var('ORBIT_AGENT_CONFIG', $originalAgentConfig);
            fleet_update_install_cli_restore_env_var('ORBIT_AGENT_HTTP_BIND', $originalAgentHttpBind);
            fleet_update_install_cli_restore_env_var('ORBIT_AGENT_LOG_PATH', $originalAgentLogPath);
            fleet_update_install_cli_restore_env_var('HOME', $originalHome);
        }

        /** @var array{error?: array{code?: string, meta?: array{stdout?: string, stderr?: string}}} $payload */
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $error = $payload['error'] ?? [];
        $meta = $error['meta'] ?? [];
        $calls = file_get_contents("{$workspace}/agent-runtime-calls.log");

        expect($exitCode)
            ->toBe(1, $output)
            ->and($error['code'] ?? null)
            ->toBe('fleet_update.cli_install_failed')
            ->and($meta['stderr'] ?? '')
            ->toContain('skip_agent_restart_no_config')
            ->and($calls)
            ->not->toContain('nohup');
    });

    it('fails closed when a loaded launchd service cannot be restarted', function (): void {
        $workspace = make_fleet_update_install_cli_workspace();
        $launchctlBin = make_fleet_update_install_cli_fake_launchctl_bin($workspace, kickstartExitCode: 42);
        $artifactPath = "{$workspace}/artifact/orbit";
        $agentArtifactPath = "{$workspace}/artifact/orbit-agent";
        file_put_contents(filename: $agentArtifactPath, data: "#!/usr/bin/env sh\necho agent\n");
        chmod(filename: $agentArtifactPath, permissions: 0o755);
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
                'sha256' => fleet_update_install_cli_sha256($artifactPath),
                'install_root' => "{$workspace}/install-root",
                'bin_path' => "{$workspace}/bin/orbit",
                'shared_binary_path' => null,
                'agent_artifact' => [
                    'artifact_url' => "file://{$agentArtifactPath}",
                    'sha256' => fleet_update_install_cli_sha256($agentArtifactPath),
                    'bin_path' => "{$workspace}/bin/orbit-agent",
                ],
                'role_images' => [],
            ], JSON_THROW_ON_ERROR),
        );

        /** @var array{error?: array{code?: string, meta?: array{stdout?: string, stderr?: string}}} $payload */
        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $error = $payload['error'] ?? [];
        $meta = $error['meta'] ?? [];
        $calls = file_get_contents("{$workspace}/launchctl-calls.log");

        expect($exitCode)
            ->toBe(1, $output)
            ->and($error['code'] ?? null)
            ->toBe('fleet_update.cli_install_failed')
            ->and($meta['stderr'] ?? '')
            ->toContain('restart_agent_launchd_failed')
            ->and($calls)
            ->toContain('launchctl kickstart -k gui/');
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

/**
 * Mirrors VersionCommand --version --local --json success envelope.
 */
function fleet_update_install_cli_fake_version_output(string $version = '9.9.9'): string
{
    return json_encode([
        'success' => [
            'data' => [
                'version' => $version,
                'latest_version' => null,
                'update_available' => false,
                'released_at' => null,
                'installed_at' => null,
            ],
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR)."\n";
}

function make_fleet_update_install_cli_workspace(): string
{
    $workspace = sys_get_temp_dir().'/orbit-fleet-update-install-cli-'.bin2hex(random_bytes(8));

    mkdir("{$workspace}/artifact", recursive: true);
    mkdir("{$workspace}/bin", recursive: true);
    $artifact = "{$workspace}/artifact/orbit";

    // Match VersionCommand JSON contract: --version --local --json.
    file_put_contents($artifact, <<<'SH'
        #!/usr/bin/env sh
        printf '%s\n' '{"success":{"data":{"version":"9.9.9","latest_version":null,"update_available":false,"released_at":null,"installed_at":null},"meta":[]}}'
        SH);
    chmod(filename: $artifact, permissions: 0o755);

    return $workspace;
}

function make_fleet_update_install_cli_path_without_docker(string $workspace): string
{
    return make_fleet_update_install_cli_restricted_path(
        workspace: $workspace,
        directory: 'path-bin',
        commands: [
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
        ],
    );
}

function make_fleet_update_install_cli_path_without_php(string $workspace): string
{
    return make_fleet_update_install_cli_restricted_path(
        workspace: $workspace,
        directory: 'path-without-php',
        commands: [
            'awk',
            'base64',
            'basename',
            'bash',
            'cat',
            'cp',
            'date',
            'dirname',
            'env',
            'head',
            'id',
            'install',
            'ln',
            'mktemp',
            'mv',
            'printf',
            'readlink',
            'rm',
            'sed',
            'sh',
            'sleep',
            'tr',
        ],
    );
}

/**
 * @param  list<string>  $commands
 */
function make_fleet_update_install_cli_restricted_path(
    string $workspace,
    string $directory,
    array $commands,
): string {
    $bin = "{$workspace}/{$directory}";

    mkdir($bin, recursive: true);

    foreach ($commands as $command) {
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

function make_fleet_update_install_cli_fake_docker_bin(string $workspace, string $log): string
{
    $bin = "{$workspace}/docker-bin";
    mkdir($bin, recursive: true);
    file_put_contents("{$bin}/docker", <<<'SH'
        #!/usr/bin/env sh
        printf '%s\n' "$*" >> "$ORBIT_TEST_DOCKER_LOG"
        if [ "$1" = "image" ] && [ "$2" = "inspect" ] && [ "${3:-}" = "--format" ]; then
            printf '%s\n' "sha256:orbit-test-image"
        fi
        exit 0
        SH);
    chmod("{$bin}/docker", permissions: 0o755);
    putenv("ORBIT_TEST_DOCKER_LOG={$log}");
    $_ENV['ORBIT_TEST_DOCKER_LOG'] = $log;
    $_SERVER['ORBIT_TEST_DOCKER_LOG'] = $log;

    return $bin;
}

function make_fleet_update_install_cli_offline_docker_bin(string $workspace, string $log): string
{
    $bin = "{$workspace}/offline-docker-bin";
    mkdir($bin, recursive: true);
    file_put_contents("{$bin}/docker", <<<'SH'
        #!/usr/bin/env sh
        printf '%s\n' "$*" >> "$ORBIT_TEST_DOCKER_LOG"

        if [ "$1" = "pull" ]; then
            exit 1
        fi

        if [ "$1" = "image" ] && [ "$2" = "inspect" ]; then
            case "$*" in
                *@sha256:*) exit 1 ;;
            esac

            if [ "${3:-}" = "--format" ]; then
                printf '%s\n' "sha256:orbit-test-image"
            fi
        fi

        exit 0
        SH);
    chmod("{$bin}/docker", permissions: 0o755);
    putenv("ORBIT_TEST_DOCKER_LOG={$log}");
    $_ENV['ORBIT_TEST_DOCKER_LOG'] = $log;
    $_SERVER['ORBIT_TEST_DOCKER_LOG'] = $log;

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
    $unit = "{$workspace}/converged-orbit-agent.service";
    $runtimeBootScript = "{$workspace}/orbit-runtime-boot-converge";
    $runtimeBootUnit = "{$workspace}/orbit-runtime-boot-converge.service";
    $realInstall = trim((string) shell_exec('command -v install'));

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
        if [ "\$last" = "/usr/local/libexec" ]; then
          exit 0
        fi
        if [ "\$last" = "/usr/local/libexec/orbit-runtime-boot-converge" ]; then
          cp "\$3" {$runtimeBootScript}
          exit 0
        fi
        if [ "\$last" = "/etc/systemd/system/orbit-runtime-boot-converge.service" ]; then
          cp "\$3" {$runtimeBootUnit}
          exit 0
        fi
        exec {$realInstall} "\$@"
        SH);
    chmod(filename: "{$bin}/install", permissions: 0o755);

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
    $unit = "{$workspace}/unexpected-orbit-agent.service";
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

    foreach (['launchctl', 'pgrep', 'ps'] as $command) {
        file_put_contents("{$bin}/{$command}", <<<SH
            #!/usr/bin/env sh
            echo "{$command} \$*" >> {$log}
            exit 1
            SH);
        chmod(filename: "{$bin}/{$command}", permissions: 0o755);
    }

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

function make_fleet_update_install_cli_fake_launchctl_bin(string $workspace, int $kickstartExitCode = 0): string
{
    $bin = "{$workspace}/launchctl-bin";
    $log = "{$workspace}/launchctl-calls.log";

    mkdir($bin, recursive: true);
    file_put_contents(filename: $log, data: '');

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
          exit {$kickstartExitCode}
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
