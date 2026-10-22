<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use App\Models\NodeRole;

describe('Node task VM settings', function (): void {
    it('selects only active configured Nodes and ignores app-dev and the former env config', function (): void {
        config(['task_vms' => task_vm_config()]);
        task_vm_node_hosts([
            ['node_id' => 9, 'cidr' => '10.251.77.0/24', 'max_vms' => 4, 'image_alias' => 'custom-base'],
            ['node_id' => 13, 'cidr' => '10.251.78.0/24', 'max_vms' => 8],
        ]);
        $services = Node::query()->create(['name' => 'services', 'status' => 'active', 'public_ssh_host' => '192.0.2.20']);
        NodeRole::query()->create(['node_id' => $services->id, 'role' => 'app-dev', 'status' => 'active']);
        config(['task_vms.incus.hosts' => [['node_id' => $services->id]]]);

        $settings = TaskVmSettings::fromConfig();
        expect(array_column($settings->hosts, 'nodeId'))->toBe([9, 13])
            ->and($settings->hasHost($services->id))->toBeFalse()
            ->and($settings->host(9)->maxVms)->toBe(4)
            ->and($settings->host(9)->imageAlias)->toBe('custom-base');
        Node::query()->findOrFail(13)->update(['status' => 'failed']);
        expect(array_column(TaskVmSettings::fromConfig()->hosts, 'nodeId'))->toBe([9]);
    });

    it('refreshes host capacity and removal between queue worker lifecycles', function (): void {
        config(['task_vms' => task_vm_config()]);
        task_vm_node_hosts([['node_id' => 9, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]]);
        expect(app(TaskVmSettings::class)->host(9)->maxVms)->toBe(4);
        task_vm_node_hosts([['node_id' => 9, 'cidr' => '10.251.77.0/24', 'max_vms' => 8]]);
        app()->forgetScopedInstances();
        expect(app(TaskVmSettings::class)->host(9)->maxVms)->toBe(8);
        task_vm_node_hosts([]);
        app()->forgetScopedInstances();
        expect(app(TaskVmSettings::class)->hosts)->toBe([]);
    });

    it('patches task VM intent without checking app storage and preserves other settings', function (): void {
        $operator = Node::query()->create(['name' => 'gateway', 'status' => 'active', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2']);
        $this->markAsGateway($operator);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $host = Node::query()->create(['name' => 'beast', 'status' => 'active', 'public_ssh_host' => '192.0.2.9', 'settings' => ['t3' => ['id' => 'retained']]]);
        $this->patchJson("/api/v1/nodes/{$host->id}/settings", ['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4]])
            ->assertOk()->assertJsonPath('data.settings.task_vms.pool', 'orbit-tasks')
            ->assertJsonPath('data.settings.task_vms.image_alias', 'orbit-task-base');
        expect($host->refresh()->settings['t3'])->toBe(['id' => 'retained']);
        $stored = $host->settings;
        $this->patchJson("/api/v1/nodes/{$host->id}/settings", ['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'warm_vms' => 0]])
            ->assertStatus(422)->assertJsonPath('error.code', 'node.settings_invalid');
        expect($host->refresh()->settings)->toBe($stored);
        $this->patchJson("/api/v1/nodes/{$host->id}/settings", ['task_vms' => null])->assertOk();
        expect($host->refresh()->settings)->toBe(['t3' => ['id' => 'retained']]);
    });

    it('rejects invalid closed host objects', function (array $entry): void {
        expect(fn () => new NodeSettingsParser()->parsePatch(json_decode(json_encode(['task_vms' => $entry]))))
            ->toThrow(ResourceOperationException::class);
    })->with([
        'Node identity' => [['node_id' => 9, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]],
        'unknown key' => [['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'extra' => true]],
        'string capacity' => [['cidr' => '10.251.77.0/24', 'max_vms' => '4']],
        'VPN overlap' => [['cidr' => '10.44.0.0/24', 'max_vms' => 4]],
        'missing fields' => [[]],
    ]);

});
