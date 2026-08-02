<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Services\Operations\FleetUpdateInstallResultInspector;
use Tests\TestCase;

uses(TestCase::class);

describe(FleetUpdateInstallResultInspector::class, function (): void {
    it('confirms agent installs from a clean JSON stdout envelope', function (): void {
        $result = new RemoteShellResult(
            exitCode: 0,
            stdout: '{"success":{"data":{"agent_installed":true}}}'."\n",
            stderr: '',
            durationMs: 1,
        );

        expect(new FleetUpdateInstallResultInspector()->expectedAgentInstallWasConfirmed(
            result: $result,
            installPayloadJson: fleet_update_install_result_inspector_payload(),
        ))->toBeTrue();
    });

    it('does not confirm agent installs from prefixed stdout', function (): void {
        $result = new RemoteShellResult(
            exitCode: 0,
            stdout: "/tmp/orbit-payload.json: OK\n".'{"success":{"data":{"agent_installed":true}}}'."\n",
            stderr: '',
            durationMs: 1,
        );

        expect(new FleetUpdateInstallResultInspector()->expectedAgentInstallWasConfirmed(
            result: $result,
            installPayloadJson: fleet_update_install_result_inspector_payload(),
        ))->toBeFalse();
    });

    it('requires a bootstrap CLI stage when Agent or role-image work is present', function (): void {
        $inspector = new FleetUpdateInstallResultInspector;

        expect($inspector->requiresBootstrapCliStage(fleet_update_install_result_inspector_payload()))
            ->toBeTrue()
            ->and($inspector->requiresBootstrapCliStage(json_encode([
                'artifact_url' => 'https://gateway.test/orbit',
                'sha256' => str_repeat(string: 'b', times: 64),
                'install_root' => '/home/orbit/orbit',
                'bin_path' => '/home/orbit/.local/bin/orbit',
                'shared_binary_path' => null,
                'agent_artifact' => null,
                'agent_service' => null,
                'role_images' => [],
                'role_image_artifacts' => [],
                'role_image_aliases' => [],
            ], JSON_THROW_ON_ERROR)))
            ->toBeFalse();
    });

    it('builds a CLI-only bootstrap payload from a full install payload', function (): void {
        $inspector = new FleetUpdateInstallResultInspector;
        $cliOnly = json_decode(
            $inspector->cliOnlyInstallPayloadJson(fleet_update_install_result_inspector_full_payload()),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($cliOnly)->toMatchArray([
            'artifact_url' => 'https://gateway.test/orbit',
            'sha256' => str_repeat(string: 'b', times: 64),
            'install_root' => '/home/orbit/orbit',
            'bin_path' => '/home/orbit/.local/bin/orbit',
            'shared_binary_path' => null,
            'agent_artifact' => null,
            'agent_service' => null,
            'role_images' => [],
            'role_image_artifacts' => [],
            'role_image_aliases' => [],
        ]);
    });
});

function fleet_update_install_result_inspector_payload(): string
{
    return json_encode([
        'agent_artifact' => [
            'artifact_url' => 'https://gateway.test/artifacts/orbit-agent',
            'sha256' => str_repeat(string: 'a', times: 64),
            'bin_path' => '/usr/local/bin/orbit-agent',
        ],
    ], JSON_THROW_ON_ERROR);
}

function fleet_update_install_result_inspector_full_payload(): string
{
    return json_encode([
        'artifact_url' => 'https://gateway.test/orbit',
        'sha256' => str_repeat(string: 'b', times: 64),
        'install_root' => '/home/orbit/orbit',
        'bin_path' => '/home/orbit/.local/bin/orbit',
        'shared_binary_path' => null,
        'agent_artifact' => [
            'artifact_url' => 'https://gateway.test/artifacts/orbit-agent',
            'sha256' => str_repeat(string: 'a', times: 64),
            'bin_path' => '/home/orbit/.local/bin/orbit-agent',
        ],
        'agent_service' => [
            'unit_name' => 'orbit-agent',
            'exec_start' => '/home/orbit/.local/bin/orbit-agent serve',
            'config_path' => '/home/orbit/.config/orbit/agent.toml',
            'config' => 'bind = "10.6.0.1:7600"',
            'ca_path' => '/home/orbit/.config/orbit/ca/root.crt',
            'ca_pem' => "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n",
            'http_bind' => '10.6.0.1:7600',
            'user' => 'orbit',
        ],
        'role_images' => ['hardimpact/orbit-reverb:2.0.0@sha256:'.str_repeat(string: 'f', times: 64)],
        'role_image_artifacts' => [],
        'role_image_aliases' => [],
    ], JSON_THROW_ON_ERROR);
}
