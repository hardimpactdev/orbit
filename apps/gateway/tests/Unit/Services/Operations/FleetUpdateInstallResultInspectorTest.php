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
