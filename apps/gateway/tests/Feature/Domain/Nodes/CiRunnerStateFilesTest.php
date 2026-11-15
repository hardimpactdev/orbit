<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Nodes\CiRunnerStateFiles;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Metrics\MetricsFootprint;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

function ciRunnerStateNode(string $name = 'ci', string $address = '10.44.0.7'): Node
{
    return Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => $name, 'public_ssh_host' => $address, 'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
}

describe('the ci-runner role runner state timer', function (): void {
    it('installs the state script, its service and the timer, and starts only the timer', function (): void {
        $node = ciRunnerStateNode();
        $role = $node->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
        $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
            'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

        $runtime = app(NativeNodeRoleRuntimeInput::class)->capture($node, $role, ['gateway_dns' => null, 'runtime' => null], $operation)['intent'];
        $files = collect($runtime['files'])->keyBy('path');

        expect($runtime['ci_host'])->toBeTrue()
            ->and($files->keys()->all())->toBe(['/usr/local/libexec/orbit-ci-runner-state', '/etc/systemd/system/orbit-ci-runner-state.service'])
            ->and($files['/usr/local/libexec/orbit-ci-runner-state'])->toMatchArray(['ensure' => 'present', 'owner' => 'root', 'group' => 'root', 'mode' => '0755',
                'content' => file_get_contents(resource_path('ci-runner/state.py'))])
            ->and($files['/etc/systemd/system/orbit-ci-runner-state.service']['content'])->toContain(
                "Type=oneshot\n",
                "ExecStart=/usr/local/libexec/orbit-ci-runner-state /var/lib/prometheus/node-exporter/orbit_ci_runners.prom\n",
                "TimeoutStartSec=10\n", "ProtectSystem=strict\n", "NoNewPrivileges=yes\n", "StateDirectory=prometheus/node-exporter\n")
            ->and(array_column($runtime['units'], 'name'))->toBe(['orbit-ci-runner-state.timer'])
            ->and($runtime['units'][0]['unit'])->toContain("OnUnitActiveSec=15s\n", "WantedBy=timers.target\n");
    });

    it('stops the timer and removes the script, the service and the textfile when the role leaves', function (bool $purge): void {
        config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
        $node = ciRunnerStateNode();
        $role = $node->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Removing]);
        $capture = app(NativeNodeRoleRemovalInput::class);

        $plan = $capture->capture($node, $role, $capture->publications($node, RoleName::CiRunner), purgeData: $purge);

        expect(array_column(array_column($plan['stop_units'], 'input'), 'unit'))->toBe(['orbit-ci-runner-state.timer'])
            ->and(array_column(array_column($plan['remove_units'], 'input'), 'unit'))->toBe(['orbit-ci-runner-state.timer'])
            ->and(array_column($plan['files'], 'path'))->toBe(['/etc/systemd/system/orbit-ci-runner-state.service', '/usr/local/libexec/orbit-ci-runner-state',
                '/var/lib/prometheus/node-exporter/orbit_ci_runners.prom', '/etc/systemd/system/orbit-ci-runner-state.timer'])
            ->and($plan['reload_units'])->toHaveCount(1);
    })->with(['keep data' => [false], 'purge' => [true]]);

    it('points the exporter textfile collector at the folder the script writes to', function (): void {
        $metrics = ciRunnerStateNode('metrics', '10.44.0.3');
        $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
        $target = ciRunnerStateNode();
        $target->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);

        $files = collect(app(MetricsFleetCapture::class)->forExporter($metrics, $target, true)['files'])->keyBy('path');

        expect($files[MetricsFootprint::ExporterDropIn]['content'])->toContain(' --collector.textfile.directory=/var/lib/prometheus/node-exporter'."\n")
            ->and(dirname(CiRunnerStateFiles::Output))->toBe(MetricsFootprint::ExporterTextfileDirectory);
    });
});
