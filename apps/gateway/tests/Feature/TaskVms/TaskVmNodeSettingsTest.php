<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Migrations\Migration;

function task_host_migration(): Migration
{
    return require database_path('migrations/2026_11_05_000000_migrate_task_vm_host_settings.php');
}

describe('Node task VM settings', function (): void {
    it('selects only active configured Nodes and ignores app-dev and the former env config', function (): void {
        config(['task_vms' => task_vm_config()]);
        task_vm_node_hosts([
            ['node_id' => 9, 'cidr' => '10.251.77.0/24', 'max_vms' => 4, 'warm_vms' => 1, 'image_alias' => 'custom-base'],
            ['node_id' => 13, 'cidr' => '10.251.78.0/24', 'max_vms' => 8],
        ]);
        $services = Node::query()->create(['name' => 'services', 'status' => 'active', 'public_ssh_host' => '192.0.2.20']);
        NodeRole::query()->create(['node_id' => $services->id, 'role' => 'app-dev', 'status' => 'active']);
        config(['task_vms.incus.hosts' => [['node_id' => $services->id]]]);

        $settings = TaskVmSettings::fromConfig();
        expect(array_column($settings->hosts, 'nodeId'))->toBe([9, 13])
            ->and($settings->hasHost($services->id))->toBeFalse()
            ->and($settings->host(9)->maxVms)->toBe(4)
            ->and($settings->host(9)->warmVms)->toBe(1)
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
        $this->patchJson("/api/v1/nodes/{$host->id}/settings", ['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'warm_vms' => 1]])
            ->assertOk()->assertJsonPath('data.settings.task_vms.pool', 'orbit-tasks')
            ->assertJsonPath('data.settings.task_vms.image_alias', 'orbit-task-base');
        expect($host->refresh()->settings['t3'])->toBe(['id' => 'retained']);
        $stored = $host->settings;
        $this->patchJson("/api/v1/nodes/{$host->id}/settings", ['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 4, 'warm_vms' => 5]])
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

    it('reads the deployment environment file even when the legacy host config is cached away', function (): void {
        $key = 'ORBIT_TASK_VMS_INCUS_HOSTS';
        $original = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        $directory = sys_get_temp_dir().'/task-vm-migration-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $environmentPath = app()->environmentPath();
        $environmentFile = app()->environmentFile();
        Node::query()->forceCreate(['id' => 9, 'name' => 'beast', 'public_ssh_host' => '192.0.2.9', 'status' => 'active']);
        file_put_contents($directory.'/.env', $key.'=\'[{"node_id":9,"cidr":"10.251.77.0/24","max_vms":8,"pool":"existing-pool"}]\''."\n");
        try {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');
            config()->set('task_vms.incus.hosts', []);
            task_host_migration()->up();
            expect(Node::query()->findOrFail(9)->settings['task_vms']['pool'])->toBe('existing-pool')
                ->and(Node::query()->findOrFail(9)->settings['task_vms']['max_vms'])->toBe(8);
        } finally {
            app()->useEnvironmentPath($environmentPath)->loadEnvironmentFrom($environmentFile);
            unlink($directory.'/.env');
            rmdir($directory);
            $original[0] === false ? putenv($key) : putenv($key.'='.$original[0]);
            foreach (['_ENV' => 1, '_SERVER' => 2] as $source => $index) {
                if ($original[$index] === null) {
                    unset($GLOBALS[$source][$key]);
                } else {
                    $GLOBALS[$source][$key] = $original[$index];
                }
            }
        }
    });

    it('copies deployment values unchanged to beast and shark without replacing existing intent', function (): void {
        $entries = [
            ['node_id' => 9, 'cidr' => '10.251.77.0/24', 'max_vms' => 8, 'warm_vms' => 2, 'pool' => 'fast', 'zfs_dataset' => 'fast/tasks', 'operator_owned' => ['orbit-ci/*'], 'memory' => '8GiB'],
            ['node_id' => 13, 'cidr' => '10.251.78.0/24', 'max_vms' => 4, 'warm_vms' => 1, 'cpus' => 4, 'disk' => '40GiB'],
        ];
        foreach ([9, 13] as $id) {
            Node::query()->forceCreate(['id' => $id, 'public_ssh_host' => '192.0.2.'.$id, 'name' => $id === 9 ? 'beast' : 'shark', 'status' => 'active', 'settings' => ['apps' => ['path' => '/srv/apps'], 't3' => ['id' => 'retained']]]);
        }
        $raw = json_encode($entries, JSON_THROW_ON_ERROR);
        putenv('ORBIT_TASK_VMS_INCUS_HOSTS='.$raw);
        $_ENV['ORBIT_TASK_VMS_INCUS_HOSTS'] = $_SERVER['ORBIT_TASK_VMS_INCUS_HOSTS'] = $raw;
        try {
            task_host_migration()->up();
            foreach ($entries as $entry) {
                $node = Node::query()->findOrFail($entry['node_id']);
                unset($entry['node_id']);
                expect(array_intersect_key($node->settings['task_vms'], $entry))->toEqual($entry)
                    ->and($node->settings['apps'])->toBe(['path' => '/srv/apps'])
                    ->and($node->settings['t3'])->toBe(['id' => 'retained'])
                    ->and($node->settings['task_vms']['image_alias'])->toBe('orbit-task-base');
            }
            $node = Node::query()->findOrFail(9);
            $stored = $node->settings;
            $stored['task_vms']['max_vms'] = 3;
            $node->update(['settings' => $stored]);
            task_host_migration()->up();
            task_host_migration()->down();
            expect($node->refresh()->settings)->toBe($stored);
        } finally {
            putenv('ORBIT_TASK_VMS_INCUS_HOSTS');
            unset($_ENV['ORBIT_TASK_VMS_INCUS_HOSTS'], $_SERVER['ORBIT_TASK_VMS_INCUS_HOSTS']);
        }
    });
});
