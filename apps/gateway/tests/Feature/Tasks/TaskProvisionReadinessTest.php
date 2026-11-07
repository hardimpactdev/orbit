<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

/** Runs the readiness exec of the task start with stub commands, as `incus exec` would run it in the VM. */
function provisionReadiness(string $handshake): string
{
    $workflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-operation-task-provision.yaml'));
    $step = collect($workflow['jobs'][0]['steps'])->firstWhere('name', 'Read the readiness');
    $argv = $step['task']['inputs']['argv'];
    $script = $argv[array_search('-c', $argv, true) + 1];
    $bin = sys_get_temp_dir().'/orbit-readiness-'.bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents("$bin/systemctl", "#!/bin/sh\nfor unit in \"\$@\"; do [ \"\$unit\" = is-active ] || echo active; done\n");
    file_put_contents("$bin/wg", "#!/bin/sh\nprintf 'hubkey=\\t%s\\n' '$handshake'\n");
    file_put_contents("$bin/incus", "#!/bin/sh\nprintf 'nested-gateway,RUNNING\\nnested-app-dev,RUNNING\\nnested-app-prod,RUNNING\\n'\n");
    file_put_contents("$bin/sleep", "#!/bin/sh\nexit 0\n");
    foreach (['systemctl', 'wg', 'incus', 'sleep'] as $tool) {
        chmod("$bin/$tool", 0755);
    }
    $result = Process::env(['PATH' => $bin.':'.getenv('PATH')])->run(['sh', '-c', $script, 'sh', 'orbit-agent.service', 'wg-quick@orbit.service', 'orbit-pi.service']);
    Process::run(['rm', '-rf', $bin]);

    return $result->output();
}

it('counts the tunnel as up once WireGuard has a handshake with the hub, and not before', function (): void {
    expect(provisionReadiness('1762526400'))->toContain("tunnel-up\n")
        ->and(substr_count(provisionReadiness('1762526400'), "active\n"))->toBe(3)
        ->and(provisionReadiness('0'))->not->toContain('tunnel-up');
});

it('counts a task VM ready only once a Swamp worker reaches it over WireGuard with its pinned key, as every later step does', function (): void {
    $workflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-operation-task-provision.yaml'));
    $steps = collect($workflow['jobs'][0]['steps'])->keyBy('name');
    $reach = $steps['Reach the VM by WireGuard']['task'];

    expect(array_column($steps['Check ready']['dependsOn'], 'step'))->toContain('Reach the VM by WireGuard')->not->toContain('Issue the model key')
        // The converge waits for the VM and its hub peer. No model key is issued: the identity exec writes the fixed one.
        ->and(array_column($steps['Converge and branch']['dependsOn'], 'step'))->toBe(['Create and start the VM', 'Add the hub peer'])
        ->and($reach['globalArgs']['hosts'][0]['address'])->toBe('${{ inputs.intent.guest_ssh.target.host }}')
        ->and($reach['globalArgs']['hosts'][0]['transport']['hostKeys'])->toBe('${{ inputs.intent.guest_ssh.target.host_keys }}')
        ->and($reach['inputs']['argv'])->toBe(['true']);
});
