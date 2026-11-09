<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

function recordsNode(string $name = 'beast', string $ip = '10.44.0.7', ?array $settings = null): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'settings' => $settings,
    ]));
}

function recordsProject(string $slug = 'hauzer'): Project
{
    return Project::query()->create(['name' => $slug, 'slug' => $slug, 'repository_url' => "https://github.com/nckrtl/{$slug}.git", 'default_branch' => 'main', 'root' => 'public']);
}

function recordsInstance(Project $project, Node $node, string $name): Instance
{
    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => "/srv/{$project->slug}/{$name}"]);
}

/** @param array<string, mixed> $overrides */
function recordsRuntime(Instance $instance, string $address, array $overrides = []): InstanceRuntime
{
    return InstanceRuntime::query()->create([
        'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'incus_project' => 'orbit-instances',
        'container_name' => "{$instance->project->slug}-{$instance->name}", 'bridge_address' => $address,
        't3_port' => $instance->name === 'default' ? null : 3800, 'runtime_version' => '2026.11.09', 'agent_version' => '2026.11.09',
        'power_state' => InstanceRuntimePowerState::Running, ...$overrides,
    ]);
}

describe('Instance bridges', function (): void {
    it('gives each Node the first free /24 of the fleet range and keeps it', function (): void {
        $beast = recordsNode();
        $shark = recordsNode('shark', '10.44.0.11');
        $allocate = app(AllocateInstanceBridgeAction::class);

        expect($allocate->execute($beast))->toBe('10.253.0.0/24')
            ->and($allocate->execute($shark))->toBe('10.253.1.0/24')
            ->and($allocate->execute($beast))->toBe('10.253.0.0/24')
            ->and($beast->refresh()->instance_bridge)->toBe('10.253.0.0/24');
    });

    it('skips a range that a task bridge or the VPN subnet overlaps', function (): void {
        recordsNode('shark', '10.44.0.11', ['task_vms' => ['cidr' => '10.253.0.0/23', 'task_places' => 1, 'ci_places' => 0,
            'containers' => ['cidr' => '10.253.3.0/25', 'task_places' => 1, 'ci_places' => 0]]]);
        app(VpnSettings::class)->configure('10.253.2.0/24');

        expect(app(AllocateInstanceBridgeAction::class)->execute(recordsNode()))->toBe('10.253.4.0/24');
    });

    it('refuses when every /24 of the fleet range is taken', function (): void {
        recordsNode('shark', '10.44.0.11', ['task_vms' => ['cidr' => '10.253.0.0/16', 'task_places' => 1, 'ci_places' => 0]]);
        $beast = recordsNode();

        expect(fn () => app(AllocateInstanceBridgeAction::class)->execute($beast))
            ->toThrow(ResourceOperationException::class, 'Every /24 of the Instance bridge range');
        expect($beast->refresh()->instance_bridge)->toBeNull();
    });

    it('never changes or shares a recorded bridge', function (): void {
        $beast = recordsNode();
        app(AllocateInstanceBridgeAction::class)->execute($beast);

        expect(fn () => Node::query()->whereKey($beast->id)->update(['instance_bridge' => '10.253.9.0/24']))->toThrow(QueryException::class, 'A Node keeps its Instance bridge.')
            ->and(fn () => Node::query()->whereKey($beast->id)->update(['instance_bridge' => null]))->toThrow(QueryException::class, 'A Node keeps its Instance bridge.')
            ->and($beast->isDirty('instance_bridge'))->toBeFalse()
            ->and(fn () => Node::query()->whereKey(recordsNode('shark', '10.44.0.11')->id)->update(['instance_bridge' => '10.253.0.0/24']))->toThrow(QueryException::class, 'UNIQUE');
    });

    it('refuses task VM host settings whose bridge overlaps an Instance bridge, also its own Node\'s', function (bool $own): void {
        $other = recordsNode('shark', '10.44.0.11');
        $beast = recordsNode();
        $beast->roles()->create(['role' => 'gateway', 'status' => 'active']);
        app(AllocateInstanceBridgeAction::class)->execute($own ? $beast : $other);

        $this->withServerVariables(['REMOTE_ADDR' => $beast->wireguard_ip])
            ->patchJson("/api/v1/nodes/{$beast->id}", ['task_vms' => ['cidr' => '10.253.0.0/24', 'task_places' => 1, 'ci_places' => 0]])
            ->assertStatus(422)->assertJsonPath('error.code', 'node.settings_invalid');
        expect($beast->refresh()->settings)->toBeNull();
    })->with(['another Node' => false, 'its own Node' => true]);
});

describe('Instance runtimes', function (): void {
    beforeEach(function (): void {
        $this->node = recordsNode();
        app(AllocateInstanceBridgeAction::class)->execute($this->node);
        $this->project = recordsProject();
        $this->default = recordsInstance($this->project, $this->node, 'default');
        $this->defaultRuntime = recordsRuntime($this->default, '10.253.0.2');
    });

    it('records a clone of the default with its container, address, port, versions and power state', function (): void {
        $clone = recordsInstance($this->project, $this->node, 'feature');
        recordsRuntime($clone, '10.253.0.3', ['clone_source_instance_id' => $this->default->id]);

        $runtime = $clone->refresh()->runtime;
        expect($runtime?->container_name)->toBe('hauzer-feature')
            ->and($runtime?->bridge_address)->toBe('10.253.0.3')
            ->and($runtime?->t3_port)->toBe(3800)
            ->and($runtime?->cloneSource?->is($this->default))->toBeTrue()
            ->and($runtime?->power_state)->toBe(InstanceRuntimePowerState::Running)
            ->and($this->default->refresh()->runtime?->t3_port)->toBeNull();
    });

    it('lets a runtime change its versions and power state only', function (): void {
        $this->defaultRuntime->update(['runtime_version' => '2026.11.10', 'agent_version' => '2026.11.10', 'power_state' => InstanceRuntimePowerState::Stopped]);
        expect($this->defaultRuntime->refresh()->power_state)->toBe(InstanceRuntimePowerState::Stopped);

        foreach ([['node_id' => recordsNode('shark', '10.44.0.11')->id], ['instance_id' => recordsInstance($this->project, $this->node, 'other')->id], ['clone_source_instance_id' => $this->default->id], ['container_name' => 'other'], ['bridge_address' => '10.253.0.9'], ['incus_project' => 'other'], ['t3_port' => 3801], ['power_state' => 'paused']] as $change) {
            expect(fn () => InstanceRuntime::query()->whereKey($this->defaultRuntime->id)->update($change))
                ->toThrow(QueryException::class, 'An Instance runtime keeps its container, address, port and clone source.');
        }
    });

    it('refuses a runtime that breaks a record rule', function (Closure $runtime): void {
        expect(fn () => $runtime->call($this))->toThrow(QueryException::class, 'Invalid Instance runtime.');
    })->with([
        'another Node than its Instance' => fn () => recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), '10.253.1.3', ['node_id' => tap(recordsNode('shark', '10.44.0.11'), app(AllocateInstanceBridgeAction::class)->execute(...))->id]),
        'a T3 port below the range' => fn () => recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), '10.253.0.3', ['t3_port' => 3799]),
        'a T3 port above the range' => fn () => recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), '10.253.0.3', ['t3_port' => 4000]),
        'a T3 port on the default Instance' => fn () => recordsRuntime(recordsInstance(recordsProject('mealou'), $this->node, 'default'), '10.253.0.3', ['t3_port' => 3801]),
        'an unknown power state' => fn () => InstanceRuntime::query()->toBase()->insert([
            'instance_id' => recordsInstance($this->project, $this->node, 'feature')->id, 'node_id' => $this->node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'hauzer-feature', 'bridge_address' => '10.253.0.3', 'runtime_version' => 'v', 'agent_version' => 'v', 'power_state' => 'paused',
        ]),
        'a default Instance cloned from another' => fn () => recordsRuntime(recordsInstance(recordsProject('mealou'), $this->node, 'default'), '10.253.0.3', ['t3_port' => null, 'clone_source_instance_id' => $this->default->id]),
        'a clone of a non-default Instance' => fn () => recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), '10.253.0.3', ['clone_source_instance_id' => recordsInstance($this->project, $this->node, 'other')->id]),
        'a clone of a checkout default' => fn () => recordsRuntime(recordsInstance(recordsProject('mealou'), $this->node, 'feature'), '10.253.0.3', ['clone_source_instance_id' => recordsInstance(Project::query()->where('slug', 'mealou')->sole(), $this->node, 'default')->id]),
        'a clone of another Project\'s default' => fn () => recordsRuntime(recordsInstance(recordsProject('mealou'), $this->node, 'feature'), '10.253.0.3', ['clone_source_instance_id' => $this->default->id]),
    ]);

    it('refuses a second runtime with the same container, address or port on the Node', function (array $overrides): void {
        recordsRuntime(recordsInstance($this->project, $this->node, 'first'), '10.253.0.3', ['clone_source_instance_id' => $this->default->id]);

        expect(fn () => recordsRuntime(recordsInstance($this->project, $this->node, 'second'), '10.253.0.4', ['clone_source_instance_id' => $this->default->id, 't3_port' => 3801, ...$overrides]))
            ->toThrow(QueryException::class, 'UNIQUE');
    })->with([
        'container' => [['container_name' => 'hauzer-first']],
        'address' => [['bridge_address' => '10.253.0.3']],
        'port' => [['t3_port' => 3800]],
    ]);

    it('refuses an address that is not a guest address of the Node\'s bridge', function (string $address): void {
        expect(fn () => recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), $address, ['clone_source_instance_id' => $this->default->id]))
            ->toThrow(LogicException::class, 'is not a guest address of the Instance bridge [10.253.0.0/24]');
    })->with(['the bridge address' => '10.253.0.1', 'the network address' => '10.253.0.0', 'the broadcast address' => '10.253.0.255', 'outside the bridge' => '10.253.1.2']);

    it('refuses a runtime on a Node without a bridge', function (): void {
        $instance = recordsInstance(recordsProject('mealou'), recordsNode('shark', '10.44.0.11'), 'default');

        expect(fn () => recordsRuntime($instance, '10.253.1.2'))->toThrow(LogicException::class, 'has no Instance bridge');
    });

    it('keeps a container Instance on its Node, Project and name, and a checkout Instance free to change', function (array $change): void {
        expect(fn () => Instance::query()->whereKey($this->default->id)->update($change))
            ->toThrow(QueryException::class, 'A container Instance keeps its Node, Project and name.');

        $checkout = recordsInstance($this->project, $this->node, 'checkout');
        Instance::query()->whereKey($checkout->id)->update(['name' => 'renamed']);
        expect($checkout->refresh()->name)->toBe('renamed');
    })->with([
        'Node' => fn () => ['node_id' => recordsNode('shark', '10.44.0.11')->id],
        'Project' => fn () => ['project_id' => recordsProject('mealou')->id],
        'name' => [['name' => 'renamed']],
    ]);

    it('keeps an Instance while its runtime exists', function (): void {
        $clone = recordsInstance($this->project, $this->node, 'feature');
        $runtime = recordsRuntime($clone, '10.253.0.3', ['clone_source_instance_id' => $this->default->id]);

        expect(fn () => Instance::query()->whereKey($clone->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY');
        $runtime->delete();
        $this->defaultRuntime->delete();
        expect(fn () => Instance::query()->whereKey($this->default->id)->delete())->not->toThrow(QueryException::class);
    });

    it('keeps a default Instance\'s runtime and name while a clone of it exists', function (): void {
        recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), '10.253.0.3', ['clone_source_instance_id' => $this->default->id]);

        expect(fn () => $this->defaultRuntime->delete())->toThrow(QueryException::class, 'A default Instance keeps its runtime while clones of it exist.')
            ->and(fn () => Instance::query()->whereKey($this->default->id)->update(['name' => 'renamed']))->toThrow(QueryException::class, 'A container Instance keeps its Node, Project and name.')
            ->and($this->default->refresh()->name)->toBe('default');
    });

    it('keeps a default Instance while a clone names it, even without its own runtime', function (): void {
        recordsRuntime(recordsInstance($this->project, $this->node, 'feature'), '10.253.0.3', ['clone_source_instance_id' => $this->default->id]);
        // Isolate the clone source's foreign key from the trigger that keeps the default's runtime.
        DB::unprepared('DROP TRIGGER instance_runtimes_delete');
        $this->defaultRuntime->delete();

        expect(fn () => Instance::query()->whereKey($this->default->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY');
    });

    it('keeps an Instance that a Conn environment names, and gives an Instance one environment', function (): void {
        $environment = fn (string $id) => ConnEnvironment::query()->forceCreate([
            'environment_id' => $id, 'label' => $id, 'url' => 'http://10.44.0.7:3800', 'node_id' => $this->node->id,
            'admin_session' => 'token', 'admin_session_expires_at' => now()->addDays(30), 'registered_at' => now(), 'instance_id' => $this->default->id,
        ]);
        $environment('env-default');
        $this->defaultRuntime->delete();

        expect(fn () => Instance::query()->whereKey($this->default->id)->delete())->toThrow(QueryException::class, 'FOREIGN KEY')
            ->and(fn () => $environment('env-other'))->toThrow(QueryException::class, 'UNIQUE');
    });
});

describe('the build list', function (): void {
    it('includes only the listed Nodes and is empty by default', function (): void {
        $beast = recordsNode();
        $shark = recordsNode('shark', '10.44.0.11');
        expect(app(ContainerNodes::class)->includes($beast))->toBeFalse();

        config(['instances.container_nodes' => [$shark->id]]);
        expect(app(ContainerNodes::class)->includes($beast))->toBeFalse()
            ->and(app(ContainerNodes::class)->includes($shark))->toBeTrue();
    });

    it('refuses a malformed list', function (mixed $list): void {
        config(['instances.container_nodes' => $list]);

        expect(fn () => app(ContainerNodes::class)->includes(recordsNode()))
            ->toThrow(ResourceOperationException::class, 'ORBIT_INSTANCES_CONTAINER_NODES must be a JSON list of Node ids');
    })->with(['malformed JSON' => [null], 'names' => [['beast']], 'an object' => [['beast' => 1]], 'zero' => [[0]]]);
});
