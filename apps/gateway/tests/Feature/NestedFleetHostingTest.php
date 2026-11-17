<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\FailUnreadableResultAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Instances\BaselineNormalInstanceContainer;
use App\Domain\Instances\BaselineNormalInstanceHostIntent;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Domain\Instances\InstanceAgentInstructions;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceMachineName;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NestedFleetLayout;
use App\Domain\Nodes\CiRunnerStateFiles;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeBridges;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationBudgetTable;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Proof\ProofSeedCopyProjection;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCopyStart;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Firewall\NodeFirewallDesiredRules;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProofSeed;
use App\Models\Schedule;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/Domain/Instances/ContainerInstanceHelpers.php';
require_once __DIR__.'/../Fixtures/BaselineNormalInstanceHostIntent.php';
beforeEach(function (): void {
    Queue::fake();
    $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
});

function nestedSwitch(Node $node, bool $on): Node
{
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $on]]);

    return $node->refresh();
}

/**
 * An Instance whose runtime records the nested project, as the image change will create one: the blanket refusal blocks reserve()
 * today, and the database keeps a recorded project from changing. So the row is the one reserve() makes, with the nested project.
 */
function recordedNestedInstance(Project $project, Node $node, string $name = 'default', ?Instance $source = null): Instance
{
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/test/'.$project->slug.'/'.$name]);
    $flag = $project->nested_fleet;
    $project->update(['nested_fleet' => false]);
    DB::beginTransaction();
    $row = app(InstanceContainer::class)->reserve($instance, $node, $source)->getAttributes();
    DB::rollBack();
    $project->update(['nested_fleet' => $flag]);
    unset($row['id']);
    InstanceRuntime::query()->create([...$row, 'incus_project' => InstancePlacement::NestedProject,
        'idmap_size' => $source?->runtime()->value('idmap_size') ?? InstanceHostIntent::TaskPlaceIds]);

    return $instance->refresh();
}

/** Task VM host settings with VM places and two task container places. @return array<string, mixed> */
/** A `task_vms` settings value as Nodes stored it before the migration removed it; no code reads it. */
function taskVms(int $taskPlaces, int $ciPlaces): array
{
    return ['cidr' => '10.252.10.0/24', 'task_places' => $taskPlaces, 'ci_places' => $ciPlaces, 'project' => 'orbit-tasks', 'network' => 'orbittask0',
        'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 2, 'project' => 'orbit-task-containers', 'network' => 'orbittaskc0']];
}

function otherProject(string $slug, bool $nested = false): Project
{
    return Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => $nested]);
}

function placementRefusal(Closure $decide): string
{
    try {
        return (string) $decide();
    } catch (ResourceOperationException $error) {
        return $error->errorCode.' '.$error->status;
    }
}

describe('the one placement rule', function (): void {
    it('places a normal Project as before and a nested-fleet Project only in a container of the nested project on a switched-on Node', function (): void {
        $listed = nestedSwitch(containerDefaultNode(), true);
        $off = containerDefaultNode('off', '10.44.0.8');
        $unlisted = nestedSwitch(containerDefaultNode('unlisted', '10.44.0.9', false), true);
        $normal = containerDefaultProject();
        $nested = otherProject('nested', true);

        expect(InstancePlacement::place($normal, $listed, true))->toBe('orbit-instances')
            ->and(InstancePlacement::place($normal, $off, true))->toBe('orbit-instances')
            ->and(InstancePlacement::place($normal, $unlisted, false))->toBeNull()
            ->and(placementRefusal(fn () => InstancePlacement::place($nested, $off, true)))->toBe('instance.nested_fleet_host_unavailable 409')
            ->and(placementRefusal(fn () => InstancePlacement::place($nested, $unlisted, true)))->toBe('instance.nested_fleet_container_required 409')
            ->and(placementRefusal(fn () => InstancePlacement::place($nested, $listed, false)))->toBe('instance.nested_fleet_container_required 409')
            ->and(InstancePlacement::place($nested, $listed, true))->toBe('orbit-instances-nested');
    });

    it('refuses a nested-fleet Project at the checkout entry and passes a normal Project', function (string $entry): void {
        $project = containerDefaultProject();
        InstancePlacement::{$entry}($project);
        $project->update(['nested_fleet' => true]);
        expect(placementRefusal(fn () => InstancePlacement::{$entry}($project)))->toBe('operation.kind_unavailable 503');
    })->with(['checkout']);

    it('checks the switch early for a writer that can make a container, before it writes anything', function (): void {
        $off = containerDefaultNode();
        $on = nestedSwitch(containerDefaultNode('shark', '10.44.0.8'), true);
        $project = containerDefaultProject();
        expect(placementRefusal(fn () => InstancePlacement::assert($project, $off)))->toBe('')
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, $on)))->toBe('');
        $project->update(['nested_fleet' => true]);
        expect(placementRefusal(fn () => InstancePlacement::assert($project, $off)))->toBe('instance.nested_fleet_host_unavailable 409')
            // A switched-on Node lets it through to place(); a missing Node is the writer's own not-found, later.
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, $on)))->toBe('')
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, null)))->toBe('');
    });

    it('lets a proof copy onto a CI-only Node whose ci-runner converge prepared nested hosting, and keeps its fleet', function (): void {
        // docs/reference/projects.md#nested-fleet-setting: a CI Node gets no switch; its own converge prepares the hosting.
        $default = proofSeedDefault();
        seedSwampNode();
        $proof = $default->project;
        $ci = ciNode();
        Node::withoutEvents(fn (): bool => $ci->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $refused = 'instance.nested_fleet_host_unavailable 409';
        expect(InstancePlacement::hostsNestedFleets($ci))->toBeFalse()
            ->and(placementRefusal(fn () => InstancePlacement::assert($proof, $ci, taskCopy: true)))->toBe($refused)
            ->and(placementRefusal(fn () => InstancePlacement::place($proof, $ci, true)))->toBe($refused)
            ->and(InstancePlacement::keepsFleet($proof, $ci, InstancePlacement::NestedProject))->toBeFalse();

        // Its passed ci-runner converge carried the nested branch: the Node hosts nested fleets, with no switch.
        endCiRunnerConverge($ci, 'succeeded');
        $ci->refresh();
        expect($ci->ci_nested_hosting)->toBeTrue()->and(app(NodeSettingsNormalizer::class)->fromStored($ci->settings)?->nested_fleet)->not->toBeTrue()
            ->and(placementRefusal(fn () => InstancePlacement::assert($proof, $ci, taskCopy: true)))->toBe('')
            ->and(InstancePlacement::place($proof, $ci, true))->toBe(InstancePlacement::NestedProject)
            ->and(InstancePlacement::keepsFleet($proof, $ci, InstancePlacement::NestedProject))->toBeTrue()
            // A normal create of the nested-fleet Project is still refused there, first.
            ->and(placementRefusal(fn () => InstancePlacement::assert($proof, $ci)))->toBe('instance.node_ci_only 409');

        // A failed converge clears the record, and so does a Node that stopped being CI-only: an app-dev role takes the switch rule.
        endCiRunnerConverge($ci, 'failed');
        expect($ci->refresh()->ci_nested_hosting)->toBeFalse()->and(InstancePlacement::hostsNestedFleets($ci))->toBeFalse();
        endCiRunnerConverge($ci, 'succeeded');
        $ci->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        expect(InstancePlacement::hostsNestedFleets($ci->refresh()))->toBeFalse();

        // A Node with the switch on hosts nested fleets as before, with no record.
        $switched = Node::query()->findOrFail($default->node_id);
        expect($switched->ci_nested_hosting)->toBeFalse()->and(InstancePlacement::hostsNestedFleets($switched))->toBeTrue()
            ->and(InstancePlacement::keepsFleet($proof, $switched, InstancePlacement::NestedProject))->toBeTrue();
    });

    it('gives the nested project its six container keys and the normal project none', function (): void {
        expect(InstancePlacement::config('orbit-instances', null))->toBe([])
            ->and(InstancePlacement::config('orbit-instances-nested', 1165536)['security.idmap.size'])->toBe('1165536')
            ->and(InstancePlacement::config('orbit-instances-nested', 101000000))->toBe([
                'security.nesting' => 'true', 'security.syscalls.intercept.mknod' => 'true', 'security.syscalls.intercept.setxattr' => 'true',
                'security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000', 'limits.memory' => '12GiB', 'boot.autostart' => 'false',
            ]);
    });
});

/** A CI Node: the `ci-runner` role in the given state, `ci.places` CI places, not on the build list and with no `app-dev` role. */
function ciNode(int $places = 3, LifecycleStatus $status = LifecycleStatus::Active, string $name = 'ci', string $ip = '10.44.0.30'): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => $ip,
        'public_ssh_host' => $ip, 'user' => 'orbit', 'settings' => ['ci' => ['places' => $places, 'proof_only' => false]],
    ]));
    $node->roles()->create(['role' => RoleName::CiRunner, 'status' => $status]);

    return $node->refresh();
}

/** @return array<string, mixed> */
function ciRunnerRuntime(Node $node): array
{
    $assignment = $node->roles()->where('role', RoleName::CiRunner)->firstOrFail();

    return app(NativeNodeRoleRuntimeInput::class)->capture($node, $assignment, [], new Operation)['intent'];
}

describe('a CI Node that hosts proof copies', function (): void {
    it('is CI-only with the ci-runner role, a CI place and no app-dev role; a role being added counts, one being removed does not', function (): void {
        expect(ProofCopyHosts::ciOnly(ciNode()))->toBeTrue()
            ->and(ProofCopyHosts::ciOnly(ciNode(1, LifecycleStatus::Provisioning, 'ci-new', '10.44.0.31')))->toBeTrue()
            ->and(ProofCopyHosts::ciOnly(ciNode(1, LifecycleStatus::Failed, 'ci-failed', '10.44.0.32')))->toBeTrue()
            ->and(ProofCopyHosts::ciOnly(ciNode(3, LifecycleStatus::Removing, 'ci-gone', '10.44.0.33')))->toBeFalse()
            ->and(ProofCopyHosts::ciOnly(ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34')))->toBeFalse();
        $both = ciNode(3, LifecycleStatus::Active, 'ci-app', '10.44.0.35');
        $both->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        expect(ProofCopyHosts::ciOnly($both))->toBeFalse()
            ->and(ProofCopyHosts::ciOnly(containerDefaultNode()))->toBeFalse();
    });

    it('offers proof places with the ci-runner role and a CI place, with or without app-dev', function (): void {
        $both = ciNode(3, LifecycleStatus::Active, 'ci-app', '10.44.0.35');
        $both->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);

        expect(ProofCopyHosts::offersPlaces(ciNode()))->toBeTrue()
            ->and(ProofCopyHosts::offersPlaces($both))->toBeTrue()
            ->and(ProofCopyHosts::offersPlaces(ciNode(1, LifecycleStatus::Provisioning, 'ci-new', '10.44.0.31')))->toBeTrue()
            ->and(ProofCopyHosts::offersPlaces(ciNode(3, LifecycleStatus::Removing, 'ci-gone', '10.44.0.33')))->toBeFalse()
            ->and(ProofCopyHosts::offersPlaces(ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34')))->toBeFalse()
            ->and(ProofCopyHosts::offersPlaces(containerDefaultNode()))->toBeFalse();
    });

    it('gives its ci-runner converge the container branch with the nested part, the CI demand and no Caddy parts', function (): void {
        $node = ciNode();
        app(NodeRoleRuntimeInput::class)->prepare($node, $node->roles()->sole());

        $host = ciRunnerRuntime($node->refresh())['instances'];
        expect($node->instance_bridge)->toBe('10.253.0.0/24')
            ->and($host['project'])->toBe('orbit-instances')
            ->and($host['cidr'])->toBe('10.253.0.0/24')
            ->and($host['nested']['project'])->toBe('orbit-instances-nested')
            ->and($host['nested']['image']['alias'])->toStartWith('orbit-instance-nested-')
            // Three places, the seed holder and the next holder, each of a new nested default's size while the proof Project has none.
            ->and($host['ids'])->toBe(13_172_736 + 5 * 101_000_000)
            ->and($host)->not->toHaveKeys(['dev_ports', 'own_site'])
            // Its converge creates the Instance pool (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node).
            ->and($host['create_pool'])->toBeTrue()
            ->and($host)->not->toHaveKey('fleet_node');
        // The Instance fleet marker changes no CI intent: only nested-app-dev's address gets `fleet_node`.
        $marker = sys_get_temp_dir().'/orbit-instance-fleet-ci-'.getmypid();
        touch($marker);
        config(['instances.fleet_marker' => $marker]);
        $marked = ciRunnerRuntime($node->refresh())['instances'];
        unlink($marker);
        expect(array_diff_key($marked, ['bridge_admitted_at' => true]))->toBe(array_diff_key($host, ['bridge_admitted_at' => true]));

        $project = otherProject('orbit', true);
        config(['proof.project' => $project->id]);
        seedSwampNode();
        recordedNestedInstance($project, nestedSwitch(containerDefaultNode(), true))->runtime()->firstOrFail()->update(['idmap_size' => 1_165_536]);
        expect(ciRunnerRuntime($node)['instances']['ids'])->toBe(13_172_736 + 5 * 1_165_536);
    });

    it('gives the bridge DHCP and the proxy, and no own-site rules', function (): void {
        $node = ciNode();
        app(InstanceHostIntent::class)->prepare($node);

        expect(array_map(static fn ($rule): string => $rule->shape->comment, app(NodeFirewallRuleCatalog::class)->instanceHostInput($node->refresh())))
            ->toBe(array_slice(NodeFirewallRuleCatalog::InstanceInputComments, 0, 2));
    });

    it('leaves the ci-runner intent of a CI Node without CI places, or with app-dev beside it, as it was', function (): void {
        $none = ciNode(0);
        $before = ciRunnerRuntime($none);
        $both = ciNode(3, LifecycleStatus::Active, 'ci-app', '10.44.0.35');
        $both->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        config(['instances.container_nodes' => [$both->id]]);
        app(NodeRoleRuntimeInput::class)->prepare($none, $none->roles()->sole());

        expect($before)->not->toHaveKey('instances')
            ->and(ciRunnerRuntime($none))->toBe($before)
            ->and($none->refresh()->instance_bridge)->toBeNull()
            ->and(ciRunnerRuntime($both))->not->toHaveKey('instances')
            ->and(app(InstanceHostIntent::class)->role($both))->toBe(RoleName::AppDev);
        // An app-dev Node's operator provides the pool: its intent never asks the converge to create one.
        app(InstanceHostIntent::class)->prepare($both);
        expect(app(InstanceHostIntent::class)->for($both->refresh()))->not->toHaveKey('create_pool');
    });

    it('refuses every create there first with instance.node_ci_only, before any record', function (bool $nested): void {
        $node = ciNode();
        $project = otherProject('tool', $nested);

        expect(placementRefusal(fn () => InstancePlacement::assert($project, $node)))->toBe('instance.node_ci_only 409')
            ->and(placementRefusal(fn () => containerCreate($project, $node)))->toBe('instance.node_ci_only 409')
            ->and(Instance::query()->count())->toBe(0)
            ->and(placementRefusal(fn () => InstancePlacement::assert($project, ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34'))))
            ->not->toBe('instance.node_ci_only 409');
        // A Node with both roles offers proof places and still takes normal Instances through app-dev.
        $both = ciNode(3, LifecycleStatus::Active, 'ci-app', '10.44.0.35');
        $both->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        expect(placementRefusal(fn () => InstancePlacement::assert($project, $both)))->not->toBe('instance.node_ci_only 409');
    })->with([false, true]);
});

/** The run's Swamp Node, whose worker reaches the default's Node over SSH for the seed listener's certificate. */
function seedSwampNode(): void
{
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.15', 'public_ssh_host' => '10.44.0.15', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
}

/** The proof Project's nested default on the build-list Node, active with a seed taken at the given time. */
function proofSeedDefault(string $takenAt = '2026-11-16 00:00:00'): Instance
{
    $project = otherProject('orbit', true);
    config(['proof.project' => $project->id]);
    $default = recordedNestedInstance($project, nestedSwitch(containerDefaultNode(), true));
    $default->update(['status' => InstanceState::Active]);
    $default->runtime()->firstOrFail()->update(['seed_commit' => str_repeat('a', 40), 'seed_taken_at' => $takenAt]);

    return $default->refresh();
}

/** Ends a ci-runner add or converge of the Node, with the role runtime that the Gateway sends now, passed or failed. */
function endCiRunnerConverge(Node $node, string $status): void
{
    $assignment = $node->roles()->where('role', RoleName::CiRunner)->sole();
    app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
    $node->refresh();
    $run = 'run-converge-'.bin2hex(random_bytes(4));
    $converge = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'triggered_by' => 'test', 'state' => ['swamp_run_id' => $run],
        'input' => ['_role' => ['assignment_id' => $assignment->id, 'role' => 'ci-runner', 'created' => true, 'certificates' => ['leaves' => []]],
            'native_role' => ['runtime' => ciRunnerRuntime($node), 'publications' => [], 'artifact_digests' => []]]]);
    $failed = $status === 'failed';
    app(NodeRoleDirectProjection::class)->apply($converge, ['run_id' => $run, 'status' => $status, 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['steps' => $failed ? ['Apply the role on the Node' => ['status' => 'failed']]
            : ['Check the role' => ['status' => 'succeeded']], 'summary' => ['failed_steps' => $failed ? ['Apply the role on the Node'] : []]]]);
}

/** The Nodes' seed listeners trust the proof default's Node, as their passed ci-runner converge recorded it. */
function seedListening(Node ...$nodes): void
{
    $sender = app(ProofSeeds::class)->defaultNode()->id ?? throw new LogicException('The proof Project has no default.');
    Node::query()->whereKey(array_map(static fn (Node $node): int => $node->id, $nodes))->update(['seed_sender_id' => $sender]);
}

describe('the CI bridge of a Node (docs/reference/development-containers.md#nodes)', function (): void {
    it('allocates no Instance bridge in a /24 that any Node\'s orbitci0 holds', function (): void {
        $other = ciNode(3, LifecycleStatus::Active, 'ci-other', '10.44.0.31');
        NodeBridges::recordCiBridge($other->id, '10.253.0.1/24');
        $node = ciNode();
        NodeBridges::recordCiBridge($node->id, '10.253.1.1/24');

        expect($other->refresh()->ci_bridge)->toBe('10.253.0.0/24')
            ->and(app(AllocateInstanceBridgeAction::class)->execute($node->refresh()))->toBe('10.253.2.0/24');
    });

    it('gives back an unused Instance bridge that clashes with the recorded CI bridge, so the next admission allocates around it', function (): void {
        $node = ciNode();
        expect(app(AllocateInstanceBridgeAction::class)->execute($node))->toBe('10.253.0.0/24');

        NodeBridges::recordCiBridge($node->id, '10.253.0.1/24');
        expect($node->refresh()->instance_bridge)->toBeNull()
            ->and(app(AllocateInstanceBridgeAction::class)->execute($node))->toBe('10.253.1.0/24');
        // An unreadable read records nothing.
        NodeBridges::recordCiBridge($node->id, '');
        NodeBridges::recordCiBridge($node->id, 'none');
        expect($node->refresh()->ci_bridge)->toBe('10.253.0.0/24');
    });

    it('keeps an Instance bridge that a container uses, whatever the CI bridge', function (): void {
        $node = containerDefaultNode();
        $bridge = app(AllocateInstanceBridgeAction::class)->execute($node);
        recordedNestedInstance(otherProject('tool', true), nestedSwitch($node, true));

        NodeBridges::recordCiBridge($node->id, $bridge);
        expect($node->refresh()->instance_bridge)->toBe($bridge)
            // The database keeps the rule too.
            ->and(fn () => Node::query()->whereKey($node->id)->update(['instance_bridge' => null]))->toThrow(QueryException::class, 'A Node keeps its Instance bridge.');
    });

    it('records the CI bridge from a ci-runner run, also a failed one, and not from another role', function (): void {
        $node = ciNode(3, LifecycleStatus::Provisioning);
        $assignment = $node->roles()->sole();
        $run = function (string $role, int $assignmentId, string $status) use ($node): void {
            $operation = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
                'status' => 'running', 'triggered_by' => 'test', 'state' => ['swamp_run_id' => 'run-'.$role],
                'input' => ['_role' => ['assignment_id' => $assignmentId, 'role' => $role, 'created' => true, 'certificates' => ['leaves' => []]],
                    'native_role' => ['runtime' => [], 'publications' => [], 'artifact_digests' => []]]]);
            $failed = $status === 'failed';
            app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => 'run-'.$role, 'status' => $status, 'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [
                    'Read the CI bridge' => ['status' => 'succeeded', 'stdout' => $role === 'ci-runner' ? "10.253.7.1/24\n" : "10.253.9.1/24\n"],
                    ...($failed ? ['Apply the role on the Node' => ['status' => 'failed']] : ['Check the role' => ['status' => 'succeeded']])],
                    'summary' => ['failed_steps' => $failed ? ['Apply the role on the Node'] : []]]]);
        };
        $run('ci-runner', $assignment->id, 'failed');
        expect($node->refresh()->ci_bridge)->toBe('10.253.7.0/24');

        $database = $node->roles()->create(['role' => RoleName::Database, 'status' => 'provisioning']);
        $run('database', $database->id, 'succeeded');
        expect($node->refresh()->ci_bridge)->toBe('10.253.7.0/24');
    });
});

describe('the seed of the proof default on each Node that offers proof places', function (): void {
    it('targets each other Node that offers proof places and is behind the default\'s current seed, never the default\'s Node', function (): void {
        $default = proofSeedDefault();
        $defaultNode = $default->node()->firstOrFail();
        $defaultNode->update(['settings' => [...$defaultNode->settings, 'ci' => ['places' => 2, 'proof_only' => false]]]);
        $defaultNode->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
        $behind = ciNode();
        $both = ciNode(2, LifecycleStatus::Active, 'ci-app', '10.44.0.35');
        $both->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
        $current = ciNode(2, LifecycleStatus::Active, 'ci-now', '10.44.0.36');
        ProofSeed::query()->create(['node_id' => $current->id, 'holder' => 'proof-seed', 'commit' => str_repeat('a', 40),
            'taken_at' => '2026-11-16 00:00:00', 'operation_id' => 7]);
        // A retake at the same commit is a new seed: the time differs.
        $retaken = ciNode(2, LifecycleStatus::Active, 'ci-old', '10.44.0.37');
        ProofSeed::query()->create(['node_id' => $retaken->id, 'holder' => 'proof-seed', 'commit' => str_repeat('a', 40),
            'taken_at' => '2026-11-15 00:00:00', 'operation_id' => 6]);
        seedListening($behind, $both, $current, $retaken, ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34'));

        expect(array_map(static fn (Node $node): string => $node->name, app(ProofSeeds::class)->targets()))->toBe(['ci', 'ci-app', 'ci-old'])
            ->and(app(ProofSeeds::class)->holder($current)?->operation_id)->toBe(7)
            ->and(app(ProofSeeds::class)->holder($retaken))->toBeNull()
            ->and(app(ProofSeeds::class)->holder($behind))->toBeNull();
    });

    it('names the default\'s current seed as the base of its next retake only while a Node that a copy would target holds it', function (): void {
        $default = proofSeedDefault();
        $runtime = $default->runtime()->firstOrFail();
        $holding = ciNode();
        $behind = ciNode(2, LifecycleStatus::Active, 'ci-behind', '10.44.0.35');
        $seed = ['holder' => 'proof-seed', 'commit' => str_repeat('a', 40), 'taken_at' => '2026-11-16 00:00:00', 'operation_id' => 7];
        ProofSeed::query()->create(['node_id' => $holding->id, ...$seed]);
        $instance = fn (): array => app(InstanceContainer::class)->intent($default->refresh(), $runtime->refresh());

        // docs/reference/development-containers.md#a-retake-keeps-the-seed: a Node whose listener is not ready is no target, so it needs no base.
        expect(app(ProofSeeds::class)->base($default, $runtime))->toBeNull()
            ->and($instance())->not->toHaveKey('seed_base');
        seedListening($holding, $behind);
        expect(app(ProofSeeds::class)->base($default, $runtime))->toBe(str_repeat('a', 40))
            ->and($instance()['seed_base'])->toBe(str_repeat('a', 40))
            // A copy to every Node behind the seed may release the base; one that leaves a Node out may not.
            ->and(app(ProofSeeds::class)->admit('seed')?->input['release_base'])->toBeTrue();
        Operation::query()->where('kind', ProofSeeds::Kind)->delete();
        expect(app(ProofSeeds::class)->admit('seed', [$behind->id]))->toBeNull()
            ->and(app(ProofSeeds::class)->admit('node_role', [$holding->id])?->input['release_base'])->toBeTrue();
        Operation::query()->where('kind', ProofSeeds::Kind)->delete();
        ProofSeed::query()->create(['node_id' => $behind->id, ...$seed, 'commit' => str_repeat('b', 40)]);
        $third = ciNode(2, LifecycleStatus::Active, 'ci-third', '10.44.0.36');
        seedListening($third);
        expect(app(ProofSeeds::class)->admit('seed', [$third->id])?->input['release_base'])->toBeFalse();

        // A Node with an older seed does not keep a base: only the current seed is one. Nor does another runtime, or a Node without places.
        ProofSeed::query()->where('node_id', $holding->id)->update(['taken_at' => '2026-11-15 00:00:00']);
        expect(app(ProofSeeds::class)->base($default, $runtime))->toBeNull();
        ProofSeed::query()->where('node_id', $holding->id)->update(['taken_at' => '2026-11-16 00:00:00']);
        Node::withoutEvents(fn (): bool => $holding->update(['settings' => ['ci' => ['places' => 0, 'proof_only' => false]]]));
        expect(app(ProofSeeds::class)->base($default, $runtime))->toBeNull()
            ->and(app(ProofSeeds::class)->base($default, InstanceRuntime::query()->whereKeyNot($runtime->id)->first() ?? new InstanceRuntime))->toBeNull();
    });

    it('asks no query for the base of any container but the proof default, which every container intent asks for', function (): void {
        $default = proofSeedDefault();
        $runtime = $default->runtime()->firstOrFail();
        $holding = ciNode();
        ProofSeed::query()->create(['node_id' => $holding->id, 'holder' => 'proof-seed', 'commit' => str_repeat('a', 40),
            'taken_at' => '2026-11-16 00:00:00', 'operation_id' => 7]);
        seedListening($holding);
        expect(app(ProofSeeds::class)->base($default, $runtime))->toBe(str_repeat('a', 40));

        // Another Project's default, another Instance of the proof Project, a runtime outside the nested project, and one without a seed.
        $others = [[$default->replicate()->forceFill(['project_id' => $default->project_id + 1]), $runtime],
            [$default->replicate()->forceFill(['name' => 'feature']), $runtime],
            [$default, $runtime->replicate()->forceFill(['incus_project' => 'default'])],
            [$default, $runtime->replicate()->forceFill(['seed_commit' => null])]];
        DB::enableQueryLog();
        try {
            foreach ($others as [$instance, $other]) {
                expect(app(ProofSeeds::class)->base($instance, $other))->toBeNull();
            }
            expect(DB::getQueryLog())->toBe([]);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    });

    it('gives the ci-runner converge of a Node that offers proof places the seed listener of the default\'s Node, and every other Node its removal', function (): void {
        $default = proofSeedDefault();
        $defaultNode = $default->node()->firstOrFail();
        seedSwampNode();
        $node = ciNode();
        app(NodeRoleRuntimeInput::class)->prepare($node, $node->roles()->sole());

        $seed = ciRunnerRuntime($node->refresh())['proof_seed'];
        expect(collect($seed)->except('sender')->all())->toBe(['present' => true, 'listen' => '10.44.0.30:8444', 'trust' => 'orbit-proof-seed',
            'project' => 'orbit-instances-nested'])
            ->and($seed['sender']['node_id'])->toBe($defaultNode->id)
            ->and($seed['sender']['ssh']['target']['host'])->toBe($defaultNode->wireguard_ip);

        // No places: the converge removes the listener of this address and the trust.
        $none = ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34');
        expect(ciRunnerRuntime($none)['proof_seed'])->toBe(['present' => false, 'listen' => '10.44.0.34:8444', 'trust' => 'orbit-proof-seed',
            'project' => 'orbit-instances-nested', 'sender' => null]);

        // The default's own Node holds the seed and listens for nobody.
        $defaultNode->update(['settings' => [...$defaultNode->settings, 'ci' => ['places' => 2, 'proof_only' => false]]]);
        $defaultNode->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
        expect(ciRunnerRuntime($defaultNode->refresh())['proof_seed']['present'])->toBeFalse();
    });

    it('admits the seed port from the default\'s Node alone, in front of the WireGuard member trust, and removes both rules on a Node without places', function (): void {
        $default = proofSeedDefault();
        $source = (string) $default->node()->firstOrFail()->wireguard_ip;
        $node = ciNode();
        $firewall = app(NodeRoleFirewallInput::class);

        $desired = $firewall->convergeCommands($node, RoleName::CiRunner)['desired'];
        $seed = array_values(array_filter($desired, static fn (array $command): bool => str_starts_with((string) end($command), 'orbit:proof-seed')));
        // Each goes in at the top: the allow last, so it is the first match.
        expect($seed)->toBe([
            ['ufw', 'insert', '1', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.30', 'port', '8444', 'comment', 'orbit:proof-seed-deny'],
            ['ufw', 'insert', '1', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', '10.44.0.30', 'port', '8444', 'comment', 'orbit:proof-seed'],
        ])
            ->and(collect(app(NodeFirewallDesiredRules::class)->forNode($node))->pluck('name')->filter(static fn (string $name): bool => str_starts_with($name, 'orbit:proof-seed'))->values()->all())
            ->toBe(['orbit:proof-seed', 'orbit:proof-seed-deny']);

        $none = ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34');
        $commands = $firewall->convergeCommands($none, RoleName::CiRunner);
        expect(array_filter($commands['desired'], static fn (array $command): bool => str_starts_with((string) end($command), 'orbit:proof-seed')))->toBe([])
            ->and(array_values(array_filter($commands['remove'], static fn (array $command): bool => str_starts_with((string) end($command), 'orbit:proof-seed'))))->toBe([
                ['ufw', '--force', 'delete', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.34', 'port', '8444', 'comment', 'orbit:proof-seed-deny'],
                ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', '10.44.0.34', 'port', '8444', 'comment', 'orbit:proof-seed'],
            ])
            ->and(collect($firewall->remove($node, RoleName::CiRunner)['remove'])->pluck('comment')->filter(static fn (string $name): bool => str_starts_with($name, 'orbit:proof-seed'))->values()->all())
            ->toBe(['orbit:proof-seed-deny', 'orbit:proof-seed']);
    });

    it('removes the seed listener, its trust entry by name and both firewall rules at a ci-runner removal', function (): void {
        $source = (string) proofSeedDefault()->node()->firstOrFail()->wireguard_ip;
        seedSwampNode();
        $node = ciNode(3, LifecycleStatus::Removing);
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $removal = app(NativeNodeRoleRemovalInput::class)->capture($node, $node->roles()->sole(), []);

        expect(collect($removal['firewall'])->pluck('input.argv')->filter(static fn (array $argv): bool => str_starts_with((string) end($argv), 'orbit:proof-seed'))->values()->all())->toBe([
            ['ufw', '--force', 'delete', 'deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.30', 'port', '8444', 'comment', 'orbit:proof-seed-deny'],
            ['ufw', '--force', 'delete', 'allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', '10.44.0.30', 'port', '8444', 'comment', 'orbit:proof-seed'],
        ]);

        // The commands run on a stub incus: the trust entry goes by the fingerprint the Node lists for its name, and only this listener goes.
        $folder = sys_get_temp_dir().'/proof-seed-removal-'.bin2hex(random_bytes(4));
        mkdir($folder.'/bin', 0700, true);
        file_put_contents($folder.'/bin/incus', "#!/bin/sh\ncase \"\$1 \$2 \$3\" in\n"
            ."  'config trust list') printf 'other,aaaa\\norbit-proof-seed,bbbb\\n';;\n"
            ."  'config get core.https_address') cat {$folder}/listener;;\n"
            ."  *) echo \"\$*\" >> {$folder}/calls;;\nesac\n");
        chmod($folder.'/bin/incus', 0755);
        $run = static function (array $action) use ($folder): void {
            $env = ['PATH' => $folder.'/bin:/usr/bin:/bin'];
            if (Process::fromShellCommandline($action['only_if'], env: $env)->run() === 0) {
                Process::fromShellCommandline($action['command'], env: $env)->mustRun();
            }
        };
        // Its own phase, before the firewall phase: the removal commands keep nothing of it.
        $actions = collect($removal['seed_listener']);
        expect($actions)->toHaveCount(2)
            ->and(collect($removal['commands'])->filter(static fn (array $action): bool => str_contains($action['command'], 'incus config'))->all())->toBe([]);
        foreach (['10.44.0.30:8444', '10.44.0.30:9443'] as $listener) {
            @unlink($folder.'/calls');
            file_put_contents($folder.'/listener', $listener."\n");
            $actions->each($run);
            expect(file($folder.'/calls', FILE_IGNORE_NEW_LINES))->toBe($listener === '10.44.0.30:8444'
                ? ['config trust remove bbbb', 'config unset core.https_address'] : ['config trust remove bbbb']);
        }
        exec('rm -rf '.escapeshellarg($folder));
    });

    it('renders a CI Node\'s firewall with the seed allow, then the seed deny, both before the WireGuard member trust', function (): void {
        $source = (string) proofSeedDefault()->node()->firstOrFail()->wireguard_ip;
        $node = ciNode();
        $commands = app(NodeRoleFirewallInput::class)->convergeCommands($node, RoleName::CiRunner)['desired'];
        // tasks/firewall.yml pairs an inserted deny with the command right after it, its allow, and checks the order against desired[0].
        $deny = array_search('orbit:proof-seed-deny', array_map(static fn (array $command): string => (string) end($command), $commands), true);
        expect(end($commands[0]))->toBe('orbit:wireguard-members')
            ->and($deny)->toBeInt()
            ->and(end($commands[$deny + 1]))->toBe('orbit:proof-seed');

        // UFW applies the commands in order: `insert 1` puts a rule first, any other rule goes last, and an existing rule is skipped.
        foreach ([[], ['orbit:wireguard-members', 'orbit-ci-runners']] as $installed) {
            $rules = array_map(static fn (string $comment): array => ['comment' => $comment, 'argv' => []], $installed);
            foreach ($commands as $command) {
                if (in_array(end($command), array_column($rules, 'comment'), true)) {
                    continue;
                }
                $rule = ['comment' => (string) end($command), 'argv' => $command];
                $command[1] === 'insert' ? array_splice($rules, (int) $command[2] - 1, 0, [$rule]) : $rules[] = $rule;
            }
            $order = array_column($rules, 'comment');
            $allow = $rules[array_search('orbit:proof-seed', $order, true)]['argv'];
            $denied = $rules[array_search('orbit:proof-seed-deny', $order, true)]['argv'];
            expect(array_search('orbit:proof-seed', $order, true))->toBeLessThan(array_search('orbit:proof-seed-deny', $order, true))
                ->and(array_search('orbit:proof-seed-deny', $order, true))->toBeLessThan(array_search('orbit:wireguard-members', $order, true))
                ->and(array_slice($allow, 3))->toBe(['allow', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', '10.44.0.30', 'port', '8444', 'comment', 'orbit:proof-seed'])
                ->and(array_slice($denied, 3))->toBe(['deny', 'in', 'on', 'orbit', 'proto', 'tcp', 'from', 'any', 'to', '10.44.0.30', 'port', '8444', 'comment', 'orbit:proof-seed-deny']);
        }

        // The Gateway's desired rule list, which the firewall check compares with the Node, holds both rules with their shapes.
        $desired = collect(app(NodeFirewallDesiredRules::class)->forNode($node))->keyBy('name');
        expect($desired['orbit:proof-seed']->shape->source)->toBe($source)
            ->and($desired['orbit:proof-seed']->shape->port)->toBe('8444')
            ->and($desired['orbit:proof-seed-deny']->shape->action)->toBe('deny')
            ->and($desired['orbit:proof-seed-deny']->shape->source)->toBe('any')
            ->and($desired->has('orbit:wireguard-members'))->toBeTrue();
    });

    it('admits one seed copy to the Nodes behind the seed, under the default\'s seed hold, with the push intent', function (): void {
        $default = proofSeedDefault();
        $runtime = $default->runtime()->firstOrFail();
        $runtime->update(['inner_volume' => 'inner-'.$default->id]);
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        seedListening($node);

        $operation = app(ProofSeeds::class)->admit('seed');
        expect($operation?->kind)->toBe('instance.seed_copy')
            ->and($operation->target_id)->toBe($default->id)
            ->and($operation->node_id)->toBe($default->node_id)
            ->and($operation->lock_key)->toBe('instance_seed:'.$default->id)
            ->and($operation->input)->toBe(['default_instance_id' => $default->id, 'commit' => str_repeat('a', 40),
                'taken_at' => $runtime->refresh()->seed_taken_at?->toIso8601String(), 'targets' => [$node->id], 'release_base' => true,
                'run_budget_seconds' => 900 + 2500])
            // One copy at a time: the open copy admits the next one when it ends.
            ->and(app(ProofSeeds::class)->admit('seed'))->toBeNull()
            ->and(Operation::query()->where('kind', 'instance.seed_copy')->count())->toBe(1);

        $intent = app(ProofSeedCopyProjection::class)->input($operation)['intent'];
        expect(collect($intent)->except(['source', 'targets'])->all())->toBe(['project' => 'orbit-instances-nested', 'pool' => 'orbit-instances',
            'snapshot' => 'seed-'.str_repeat('a', 40), 'release_base' => true, 'holder' => 'proof-seed', 'holder_volume' => 'proof-seed-inner',
            'holder_nic_acl' => 'orbitinst0-proxy'])
            ->and(collect($intent['source'])->except('ssh_execution')->all())->toBe(['node_id' => $default->node_id,
                'container' => $runtime->container_name, 'inner_volume' => 'inner-'.$default->id])
            ->and(collect($intent['targets'][0])->except('ssh_execution')->all())->toBe(['node_id' => $node->id, 'remote' => 'proof-node-'.$node->id,
                'listen' => '10.44.0.30:8444'])
            ->and($intent['targets'][0]['ssh_execution']['target']['host'])->toBe('10.44.0.30');
    });

    it('captures a run budget that holds the copies of its targets in series, and takes no more targets than the serve run cap holds', function (): void {
        proofSeedDefault();
        seedSwampNode();
        $nodes = array_map(static fn (int $index): Node => ciNode(2, LifecycleStatus::Active, 'ci-'.$index, '10.44.0.'.(40 + $index)), range(1, 6));
        seedListening(...$nodes);
        $budget = static fn (Operation $copy): int => OperationRunBudgets::seconds($copy->forceFill(['attempts' => 1]));

        // docs/reference/operations.md: the fixed phases, plus one copy step per target, because the copies run one target at a time.
        $one = app(ProofSeeds::class)->admit('seed', array_map(static fn (Node $node): int => $node->id, array_slice($nodes, 1)));
        expect($one?->input['targets'])->toBe([$nodes[0]->id])
            ->and($budget($one))->toBe(900 + 2500);
        Operation::query()->where('kind', ProofSeeds::Kind)->delete();
        $three = app(ProofSeeds::class)->admit('seed', array_map(static fn (Node $node): int => $node->id, array_slice($nodes, 3)));
        expect($three?->input['targets'])->toHaveCount(3)
            ->and($budget($three))->toBe(900 + 3 * 2500);
        Operation::query()->where('kind', ProofSeeds::Kind)->delete();

        // Six Nodes behind: the run takes the five that fit in the cap and may not release the base; its end admits the sixth.
        $capped = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        expect(ProofSeeds::maxTargets())->toBe(5)
            ->and($capped->input['targets'])->toBe(array_map(static fn (Node $node): int => $node->id, array_slice($nodes, 0, 5)))
            ->and($capped->input['release_base'])->toBeFalse()
            ->and($budget($capped))->toBe(900 + 5 * 2500)
            ->and($budget($capped))->toBeLessThanOrEqual(OperationBudgetTable::longestFixedSeconds());
        $capped->forceFill(['status' => 'running', 'state' => [...($capped->state ?? []), 'swamp_run_id' => 'run-1']])->save();
        app(ProofSeedCopyProjection::class)->apply($capped, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['targets' => array_map(static fn (int $node): array => ['node_id' => $node,
                'status' => 'succeeded'], $capped->input['targets'])]]);
        expect(Operation::query()->where('kind', ProofSeeds::Kind)->whereKeyNot($capped->id)->sole()->input['targets'])->toBe([$nodes[5]->id]);
    });

    it('records the seed of each passed Node and an Activity problem for each failed one, and tries no failed Node again at the same seed', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $passed = ciNode();
        $failed = ciNode(2, LifecycleStatus::Active, 'ci-two', '10.44.0.31');
        foreach ([$passed, $failed] as $node) {
            Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        }
        seedListening($passed, $failed);
        $operation = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        $operation->forceFill(['status' => 'running', 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-1']])->save();

        app(ProofSeedCopyProjection::class)->apply($operation, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['targets' => [['node_id' => $passed->id, 'status' => 'succeeded'],
                ['node_id' => $failed->id, 'status' => 'failed', 'failed_phase' => 'copy', 'detail' => 'Error: no free ID range']]]]);

        $seed = ProofSeed::query()->where('node_id', $passed->id)->sole();
        expect([$seed->holder, $seed->commit, $seed->operation_id])->toBe(['proof-seed', str_repeat('a', 40), $operation->id])
            ->and(app(ProofSeeds::class)->holder($passed)?->holder)->toBe('proof-seed')
            ->and(ProofSeed::query()->where('node_id', $failed->id)->exists())->toBeFalse()
            ->and($operation->refresh()->result['failed'])->toBe([['node_id' => $failed->id, 'detail' => 'Error: no free ID range']]);
        $problem = Activity::query()->where('description', 'instance.seed_copy_failed')->sole();
        expect($problem->subject_id)->toBe($failed->id)
            ->and($problem->properties['operation_id'])->toBe($operation->id);
        // A Node that failed at the same seed waits for the next seed or its own converge: a lasting failure does not repeat.
        expect(Operation::query()->where('kind', 'instance.seed_copy')->count())->toBe(1);
    });

    it('admits the next copy at its end for every Node behind a seed that moved, and else only for Nodes that no copy has tried', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $first = ciNode();
        Node::withoutEvents(fn (): bool => $first->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        seedListening($first);
        $end = function (Operation $copy): void {
            $copy->forceFill(['status' => 'running', 'state' => [...($copy->state ?? []), 'swamp_run_id' => 'run-'.$copy->id]])->save();
            app(ProofSeedCopyProjection::class)->apply($copy, ['status' => 'failed', 'run_id' => 'run-'.$copy->id, 'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(), 'result' => ['targets' => array_map(static fn (int $node): array => ['node_id' => $node,
                    'status' => 'failed', 'detail' => 'no listener'], $copy->input['targets'])]]);
        };
        $copy = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        // A Node that became a target while the copy held the lock: its own converge was refused as busy.
        $second = ciNode(2, LifecycleStatus::Active, 'ci-two', '10.44.0.31');
        Node::withoutEvents(fn (): bool => $second->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        seedListening($second);
        $end($copy);
        $next = Operation::query()->where('kind', 'instance.seed_copy')->whereKeyNot($copy->id)->sole();
        expect($next->input['targets'])->toBe([$second->id])
            ->and($next->triggered_by)->toBe('seed_copy');

        // The default retakes its seed while the next copy runs: its end tries every Node behind the new seed.
        $default->runtime()->firstOrFail()->update(['seed_taken_at' => '2026-11-16 02:00:00']);
        $end($next);
        $moved = Operation::query()->where('kind', 'instance.seed_copy')->whereKeyNot([$copy->id, $next->id])->sole();
        expect($moved->input['targets'])->toBe([$first->id, $second->id])
            ->and($moved->input['taken_at'])->toBe($default->runtime()->firstOrFail()->seed_taken_at?->toIso8601String());
    });

    it('stops two Nodes that keep failing at one seed after each was tried once, and tries both again at a moved seed', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $node = static function (string $name, string $ip): Node {
            $node = ciNode(2, LifecycleStatus::Active, $name, $ip);
            Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
            seedListening($node);

            return $node;
        };
        $fail = function (Operation $copy): void {
            $copy->forceFill(['status' => 'running', 'state' => [...($copy->state ?? []), 'swamp_run_id' => 'run-'.$copy->id]])->save();
            app(ProofSeedCopyProjection::class)->apply($copy, ['status' => 'failed', 'run_id' => 'run-'.$copy->id, 'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(), 'result' => ['targets' => array_map(static fn (int $id): array => ['node_id' => $id,
                    'status' => 'failed', 'detail' => 'no listener'], $copy->input['targets'])]]);
        };
        $first = $node('ci-a', '10.44.0.30');
        $copyA = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        // The second Node's own trigger finds the copy's lock busy.
        $second = $node('ci-b', '10.44.0.31');
        $fail($copyA);
        $copyB = Operation::query()->where('kind', 'instance.seed_copy')->whereKeyNot($copyA->id)->sole();
        expect($copyB->input['targets'])->toBe([$second->id]);

        // Each Node was tried once at this seed: the end of the second copy admits nothing, so the chain stops.
        $fail($copyB);
        expect(Operation::query()->where('kind', 'instance.seed_copy')->count())->toBe(2);

        // A new seed tries both again.
        $default->runtime()->firstOrFail()->update(['seed_taken_at' => '2026-11-16 03:00:00']);
        expect(app(ProofSeeds::class)->admit('seed')?->input['targets'])->toBe([$first->id, $second->id]);
    });

    it('ends a copy whose passed target was deleted during the run, records no seed for it and frees the lock for the next trigger', function (): void {
        proofSeedDefault();
        seedSwampNode();
        $gone = ciNode();
        $kept = ciNode(2, LifecycleStatus::Active, 'ci-two', '10.44.0.31');
        foreach ([$gone, $kept] as $node) {
            Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        }
        seedListening($gone, $kept);
        $copy = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        $copy->forceFill(['status' => 'running', 'state' => [...($copy->state ?? []), 'swamp_run_id' => 'run-1']])->save();
        $gone->roles()->delete();
        Node::withoutEvents(fn (): ?bool => $gone->delete());

        app(ProofSeedCopyProjection::class)->apply($copy, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['targets' => [['node_id' => $gone->id, 'status' => 'succeeded'],
                ['node_id' => $kept->id, 'status' => 'failed', 'detail' => 'no listener']]]]);

        expect($copy->refresh()->status->terminal())->toBeTrue()
            ->and($copy->lock_key)->toBeNull()
            ->and(ProofSeed::query()->count())->toBe(0)
            ->and($copy->result['passed'])->toBe([])
            // The kept Node's own converge is the next trigger: the lock is free.
            ->and(app(ProofSeeds::class)->admit('node_role')?->input['targets'])->toBe([$kept->id]);
    });

    it('admits the seed copy when a deployment of the proof default records a new seed', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        seedListening($node);
        $commit = str_repeat('b', 40);
        $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => 'running', 'triggered_by' => 'release', 'lock_key' => 'instance:'.$default->id,
            'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($default)],
            'state' => ['swamp_run_id' => 'run-1', ProofSeeds::HoldState => ProofSeeds::Holding]]);

        app(InstanceDeployProjection::class)->apply($deploy, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)]],
                'summary' => ['failed_steps' => []]]]);

        $copy = Operation::query()->where('kind', 'instance.seed_copy')->sole();
        expect($copy->input['commit'])->toBe($commit)
            ->and($copy->input['targets'])->toBe([$node->id])
            ->and($copy->triggered_by)->toBe('seed');
    });

    it('makes a deployment of the proof default wait for the seed while a seed copy pushes it, then hold it (seed copy first)', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        seedListening($node);
        $copy = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => 'running', 'triggered_by' => 'release', 'lock_key' => 'instance:'.$default->id,
            'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($default)], 'state' => ['swamp_run_id' => '0b6f2a52-0000-4000-8000-000000000001']]);

        // The deployment's intent asks for the hold; the seed copy holds it, so the step waits and asks again.
        expect(app(InstanceContainer::class)->intent($default->refresh(), $default->runtime()->firstOrFail())['seed_hold'] ?? null)->toBeTrue()
            ->and(app(ProofSeeds::class)->hold($deploy))->toBeFalse()
            ->and($deploy->refresh()->state[ProofSeeds::HoldState])->toBe('waiting');
        config(['operations.seed_hold_wait_seconds' => 0]);
        // The run's call is from the Swamp Node, as a WireGuard peer.
        app()->forgetInstance(RequireActiveWireGuardPeer::class);
        $this->withServerVariables(['REMOTE_ADDR' => Node::query()->findOrFail(SwampNode::id())->wireguard_ip])
            ->postJson('/api/v1/direct-operations/'.$deploy->id.'/seed-hold', ['run_id' => '0b6f2a52-0000-4000-8000-000000000001'])
            ->assertStatus(423)->assertJsonPath('error.code', 'instance.seed_busy');

        // The copy ends with every snapshot it pushed; then the deployment holds the seed, and a retry of the call keeps it.
        $copy->forceFill(['status' => 'running', 'state' => [...($copy->state ?? []), 'swamp_run_id' => 'run-copy']])->save();
        app(ProofSeedCopyProjection::class)->apply($copy, ['status' => 'succeeded', 'run_id' => 'run-copy', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['targets' => [['node_id' => $node->id, 'status' => 'succeeded']]]]);
        expect($copy->refresh()->status->terminal())->toBeTrue()
            ->and(ProofSeed::query()->where('node_id', $node->id)->sole()->commit)->toBe(str_repeat('a', 40))
            ->and(app(ProofSeeds::class)->hold($deploy))->toBeTrue()
            ->and(app(ProofSeeds::class)->hold($deploy))->toBeTrue()
            ->and($deploy->refresh()->state[ProofSeeds::HoldState])->toBe('held');
        $this->withServerVariables(['REMOTE_ADDR' => Node::query()->findOrFail(SwampNode::id())->wireguard_ip])
            ->postJson('/api/v1/direct-operations/'.$deploy->id.'/seed-hold', ['run_id' => '0b6f2a52-0000-4000-8000-000000000001'])
            ->assertOk()->assertJsonPath('data.held', true);
    });

    it('admits no seed copy at the end of a copy while a deployment waits for the seed; the deployment holds it next and its end admits the copy', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $first = ciNode(3, LifecycleStatus::Active, 'ci-1', '10.44.0.41');
        $later = ciNode(3, LifecycleStatus::Active, 'ci-2', '10.44.0.42');
        foreach ([$first, $later] as $node) {
            Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        }
        seedListening($first, $later);
        // The copy runs for the first Node; the later Node is untried at the seed, so the copy's end would admit a copy for it.
        $copy = app(ProofSeeds::class)->admit('seed', [$later->id]) ?? throw new LogicException('The copy was not admitted.');
        $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => 'running', 'triggered_by' => 'release', 'lock_key' => 'instance:'.$default->id,
            'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($default)], 'state' => ['swamp_run_id' => 'run-deploy']]);
        expect(app(ProofSeeds::class)->hold($deploy))->toBeFalse();

        $copy->forceFill(['status' => 'running', 'state' => [...($copy->state ?? []), 'swamp_run_id' => 'run-copy']])->save();
        app(ProofSeedCopyProjection::class)->apply($copy, ['status' => 'succeeded', 'run_id' => 'run-copy', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['targets' => [['node_id' => $first->id, 'status' => 'succeeded']]]]);

        // The waiting deployment goes first: no copy starts, and its next hold request is granted.
        expect(Operation::query()->where('kind', ProofSeeds::Kind)->whereNotNull('lock_key')->count())->toBe(0)
            ->and(Operation::query()->where('kind', ProofSeeds::Kind)->count())->toBe(1)
            ->and(app(ProofSeeds::class)->hold($deploy))->toBeTrue();

        $commit = str_repeat('b', 40);
        app(InstanceDeployProjection::class)->apply($deploy->refresh(), ['status' => 'succeeded', 'run_id' => 'run-deploy', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)]],
                'summary' => ['failed_steps' => []]]]);

        // The seed moved: the deployment's end admits the copy to every Node behind it.
        $next = Operation::query()->where('kind', ProofSeeds::Kind)->whereNotNull('lock_key')->sole();
        expect($next->input['commit'])->toBe($commit)
            ->and($next->input['targets'])->toBe([$first->id, $later->id])
            ->and($next->triggered_by)->toBe('seed');
    });

    it('refuses a seed copy while a deployment holds the default\'s seed, and admits it when the deployment ends (deployment first)', function (string $end): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        seedListening($node);
        $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => 'running', 'triggered_by' => 'release', 'lock_key' => 'instance:'.$default->id,
            'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($default)], 'state' => ['swamp_run_id' => 'run-1']]);
        expect(app(ProofSeeds::class)->hold($deploy))->toBeTrue();

        // A Node's own converge asks for a copy while the retake runs: it is not admitted.
        expect(app(ProofSeeds::class)->admit('node_role', node: $node->id))->toBeNull()
            ->and(Operation::query()->where('kind', ProofSeeds::Kind)->count())->toBe(0);

        $commit = str_repeat('b', 40);
        if (in_array($end, ['succeeded', 'failed'], true)) {
            app(InstanceDeployProjection::class)->apply($deploy, ['status' => $end, 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
                'finished_at' => now()->toIso8601String(), 'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode(
                    $end === 'succeeded' ? ['commit' => $commit] : ['error_code' => 'instance.container_seed_failed'], JSON_THROW_ON_ERROR)]],
                    'summary' => ['failed_steps' => $end === 'succeeded' ? [] : ['Finish the container']]]]);
        } elseif ($end === 'unreadable') {
            app(FailUnreadableResultAction::class)->execute($deploy, 'run-1', ['status' => 'succeeded', 'completedAt' => now()->toIso8601ZuluString()],
                'Finish the container', 'skipped');
        } else {
            // A timed-out deployment keeps its Instance lock, and so its claim on the seed, until its run's end is settled or the hold goes stale.
            $deploy->update(['started_at' => now()->subDays(1)]);
            app(ExpireOperationAction::class)->execute($deploy->refresh());
            expect($deploy->refresh()->error_code)->toBe('operation.timed_out')
                ->and(app(ProofSeeds::class)->admit('node_role', node: $node->id))->toBeNull()
                ->and(Operation::query()->where('kind', ProofSeeds::Kind)->count())->toBe(0);
            if ($end === 'settled') {
                app(ExpireOperationAction::class)->settle($deploy, 'run-1', 'cancelled');
            } else {
                $deploy->update(['finished_at' => now()->subMinutes(ExpireOperationAction::HoldMinutes + 1)]);
                expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(1);
            }
        }

        // Every end gives the seed back and admits the copy, for the new seed or for the old one that the Node still lacks.
        $copy = Operation::query()->where('kind', ProofSeeds::Kind)->sole();
        expect($deploy->refresh()->lock_key)->toBeNull()
            ->and($copy->input['targets'])->toBe([$node->id])
            ->and($copy->input['commit'])->toBe($end === 'succeeded' ? $commit : str_repeat('a', 40))
            ->and($copy->lock_key)->toBe('instance_seed:'.$default->id);
    })->with(['passed' => 'succeeded', 'failed' => 'failed', 'timed out, then settled' => 'settled', 'timed out, then released stale' => 'stale',
        'unreadable result' => 'unreadable']);

    it('admits the seed copy when a ci-runner add of a Node that offers proof places passes', function (): void {
        proofSeedDefault();
        seedSwampNode();
        $node = ciNode(3, LifecycleStatus::Provisioning);
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $assignment = $node->roles()->sole();

        endCiRunnerConverge($node, 'succeeded');

        expect($assignment->refresh()->status)->toBe(LifecycleStatus::Active)
            ->and(Operation::query()->where('kind', 'instance.seed_copy')->sole()->input['targets'])->toBe([$node->id]);
    });

    it('keeps the converged Node in the run when more Nodes are behind the seed than one run takes', function (): void {
        proofSeedDefault();
        seedSwampNode();
        $nodes = array_map(static fn (int $index): Node => ciNode(2, LifecycleStatus::Active, 'ci-'.$index, '10.44.0.'.(40 + $index)), range(1, 6));
        seedListening(...array_slice($nodes, 0, 5));
        $converged = $nodes[5];
        Node::withoutEvents(fn (): bool => $converged->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));

        // The end of a run admits only Nodes that no copy has tried at the seed, so a run without the converged Node would leave it behind.
        endCiRunnerConverge($converged, 'succeeded');

        $targets = Operation::query()->where('kind', ProofSeeds::Kind)->sole()->input['targets'];
        expect($targets)->toHaveCount(ProofSeeds::maxTargets())
            ->and($targets)->toContain($converged->id)
            ->and($targets)->toBe([...array_map(static fn (Node $node): int => $node->id, array_slice($nodes, 0, 4)), $converged->id]);
    });

    it('targets only a Node whose passed ci-runner converge opened the seed listener for the default\'s Node, and never marks another tried', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $ready = ciNode();
        $waiting = ciNode(2, LifecycleStatus::Active, 'ci-two', '10.44.0.31');
        foreach ([$ready, $waiting] as $node) {
            Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        }
        seedListening($ready);
        $tried = static fn (): array => Operation::query()->where('kind', 'instance.seed_copy')->get()
            ->flatMap(static fn (Operation $copy): array => $copy->input['targets'])->unique()->values()->all();

        // The Node with places whose converge has not opened the listener yet is no target: no copy fails on it.
        $copy = app(ProofSeeds::class)->admit('seed') ?? throw new LogicException('The copy was not admitted.');
        expect($copy->input['targets'])->toBe([$ready->id]);
        $copy->forceFill(['status' => 'running', 'state' => [...($copy->state ?? []), 'swamp_run_id' => 'run-1']])->save();
        app(ProofSeedCopyProjection::class)->apply($copy, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['targets' => [['node_id' => $ready->id, 'status' => 'failed', 'detail' => 'no free ID range']]]]);
        // The end of the copy admits nothing, and the waiting Node is not tried.
        expect($tried())->toBe([$ready->id]);

        // Its converge passes: the Gateway records the default's Node as its sender, and the converge's own trigger copies to it.
        endCiRunnerConverge($waiting, 'succeeded');
        expect($waiting->refresh()->seed_sender_id)->toBe($default->node_id)
            ->and(Operation::query()->where('kind', 'instance.seed_copy')->latest('id')->firstOrFail()->input['targets'])->toBe([$ready->id, $waiting->id]);

        // A failed converge may have changed the trust: the record goes. So does a record of a former default's Node.
        endCiRunnerConverge($waiting, 'failed');
        Node::query()->whereKey($ready->id)->update(['seed_sender_id' => $waiting->id]);
        expect($waiting->refresh()->seed_sender_id)->toBeNull()
            ->and(app(ProofSeeds::class)->targets())->toBe([]);
    });

    it('clears the record when the listener goes: a passed converge without places, and a ci-runner removal, passed or failed', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $places = static fn (int $count): bool => Node::withoutEvents(fn (): bool => $node->update(['settings' => ['ci' => ['places' => $count, 'proof_only' => false]]]));

        endCiRunnerConverge($node, 'succeeded');
        expect($node->refresh()->seed_sender_id)->toBe($default->node_id);
        $places(0);
        endCiRunnerConverge($node->refresh(), 'succeeded');
        expect($node->refresh()->seed_sender_id)->toBeNull();

        foreach (['failed', 'succeeded'] as $status) {
            $places(3);
            endCiRunnerConverge($node->refresh(), 'succeeded');
            expect($node->refresh()->seed_sender_id)->toBe($default->node_id);
            $assignment = $node->roles()->sole();
            $assignment->update(['status' => LifecycleStatus::Removing]);
            $removal = Operation::query()->create(['kind' => 'node.role_remove', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
                'status' => 'running', 'triggered_by' => 'test', 'state' => ['swamp_run_id' => 'run-remove-'.$status],
                'input' => ['_role_remove' => ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => 'ci-runner'], 'intent' => []]]);
            app(NodeRoleRemovalProjection::class)->apply($removal, ['run_id' => 'run-remove-'.$status, 'status' => $status,
                'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $status === 'succeeded'
                    ? ['steps' => ['Confirm role removal' => ['status' => 'succeeded']]] : ['failed_step' => 'Stop the seed listener', 'error_code' => 'node_role.removal_failed']]);

            // A failed removal leaves the role, which offers places again; its listener may be gone, so it is no target.
            expect($node->refresh()->seed_sender_id)->toBeNull()
                ->and(app(ProofSeeds::class)->targets())->toBe([]);
            if ($status === 'failed') {
                expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Failed);
            }
        }
    });

    it('records the hold script of a ci-runner add or converge by one rule, and takes proof copies only with this release\'s script', function (): void {
        // docs/reference/node-provisioning.md#proof-places-on-a-ci-node
        proofSeedDefault();
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        expect($node->ci_hold_digest)->toBeNull()->and(ProofCopyHosts::offersPlaces($node))->toBeTrue()
            ->and(ProofCopyHosts::takesCopies($node))->toBeFalse();

        endCiRunnerConverge($node, 'succeeded');
        expect($node->refresh()->ci_hold_digest)->toBe(hash('sha256', CiRunnerStateFiles::holdScript()))
            ->and(ProofCopyHosts::takesCopies($node))->toBeTrue();

        // A release that changes the script: the Node takes no copy until a converge installs it.
        Node::query()->whereKey($node->id)->update(['ci_hold_digest' => hash('sha256', 'an older hold script')]);
        expect(ProofCopyHosts::takesCopies($node->refresh()))->toBeFalse();

        // A failed converge may have changed the script: the record goes.
        endCiRunnerConverge($node, 'succeeded');
        endCiRunnerConverge($node->refresh(), 'failed');
        expect($node->refresh()->ci_hold_digest)->toBeNull();

        // A ci-runner removal, passed or failed, removes the script.
        foreach (['failed', 'succeeded'] as $status) {
            $assignment = $node->roles()->sole();
            $assignment->update(['status' => LifecycleStatus::Active]);
            endCiRunnerConverge($node->refresh(), 'succeeded');
            expect($node->refresh()->ci_hold_digest)->not->toBeNull()->and($node->ci_nested_hosting)->toBeTrue();
            $assignment->refresh()->update(['status' => LifecycleStatus::Removing]);
            $removal = Operation::query()->create(['kind' => 'node.role_remove', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
                'status' => 'running', 'triggered_by' => 'test', 'state' => ['swamp_run_id' => 'run-hold-remove-'.$status],
                'input' => ['_role_remove' => ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => 'ci-runner'], 'intent' => []]]);
            app(NodeRoleRemovalProjection::class)->apply($removal, ['run_id' => 'run-hold-remove-'.$status, 'status' => $status,
                'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $status === 'succeeded'
                    ? ['steps' => ['Confirm role removal' => ['status' => 'succeeded']]] : ['failed_step' => 'Remove the role files', 'error_code' => 'node_role.removal_failed']]);

            expect($node->refresh()->ci_hold_digest)->toBeNull()->and($node->ci_nested_hosting)->toBeFalse();
        }
    });

    it('has no targets while the proof default has no seed', function (): void {
        $default = proofSeedDefault();
        ciNode();
        $default->runtime()->firstOrFail()->update(['seed_commit' => null]);

        expect(app(ProofSeeds::class)->targets())->toBe([]);
    });

    it('starts a copy from the seed of its own Node: the default\'s pair on its Node, the current holder on another, none on an old seed', function (): void {
        $default = proofSeedDefault();
        $runtime = $default->runtime()->firstOrFail();
        $runtime->update(['inner_volume' => 'inner-'.$default->id]);
        $node = ciNode();
        $commit = str_repeat('a', 40);

        expect(TaskCopyStart::seed($runtime->refresh(), $default->node()->firstOrFail()))->toBe(['source' => $runtime->container_name,
            'snapshot' => 'seed-'.$commit, 'seed_commit' => $commit, 'inner_volume' => 'inner-'.$default->id])
            ->and(placementRefusal(fn () => TaskCopyStart::seed($runtime, $node)))->toBe('tasks.copy_source_unavailable 409');

        ProofSeed::query()->create(['node_id' => $node->id, 'holder' => 'proof-seed', 'commit' => $commit, 'taken_at' => '2026-11-16 00:00:00', 'operation_id' => 9]);
        expect(TaskCopyStart::seed($runtime, $node))->toBe(['source' => 'proof-seed', 'snapshot' => 'seed-'.$commit,
            'seed_commit' => $commit, 'inner_volume' => 'proof-seed-inner']);

        $runtime->update(['seed_taken_at' => '2026-11-16 01:00:00']);
        expect(placementRefusal(fn () => TaskCopyStart::seed($runtime->refresh(), $node)))->toBe('tasks.copy_source_unavailable 409');
    });
});

describe('the recorded Incus project', function (): void {
    it('records the normal project at reservation, and a clone records its default\'s project', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        expect(app(InstanceContainer::class)->reserve($default, $node)->incus_project)->toBe('orbit-instances');

        $nestedDefault = recordedNestedInstance(otherProject('other'), $node);
        $clone = Instance::query()->create(['project_id' => $nestedDefault->project_id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
        expect(app(InstanceContainer::class)->reserve($clone, $node, $nestedDefault)->incus_project)->toBe('orbit-instances-nested');
    });

    it('names the workspace, the destroy target and the intent after the recorded project, not the Project flag', function (): void {
        $node = containerDefaultNode();
        $instance = recordedNestedInstance(containerDefaultProject(), $node);
        expect($instance->project->nested_fleet)->toBeFalse()
            ->and(InstanceContainer::workspaceOnNode($instance))->toBe('/var/lib/incus/storage-pools/orbit-instances/containers/orbit-instances-nested_instance-'.$instance->id.'/rootfs/srv/workspace')
            ->and(app(ContainerInstanceDestroy::class)->capture($instance)['project'])->toBe('orbit-instances-nested')
            ->and(app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail())['project'])->toBe('orbit-instances-nested');
    });

    it('gives a recorded nested container its keys and its exact device list for the start guard', function (bool $clone): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = recordedNestedInstance($project, $node);
        if ($clone) {
            $instance->runtime()->firstOrFail()->update(['seed_commit' => str_repeat('a', 40)]);
            $instance = recordedNestedInstance($project, $node, 'feature', $instance);
        }
        $runtime = $instance->runtime()->firstOrFail();
        $intent = app(InstanceContainer::class)->intent($instance, $runtime);
        expect($intent['config'])->toMatchArray(InstancePlacement::config('orbit-instances-nested', $runtime->idmap_size))
            ->and($intent['config'])->not->toHaveKeys(['raw.lxc', 'raw.idmap', 'security.privileged'])
            // A nested runtime starts from the nested-fleet image; the normal intent below keeps `orbit-instance`.
            ->and($intent['image'])->toBe('orbit-instance-nested')
            // The default's root size is the Gateway setting; a copy keeps 40GiB, whose quota counts only its own blocks.
            ->and($intent['root_disk_size'])->toBe($clone ? '40GiB' : '80GiB')
            ->and($intent['allowed_devices'])->toBe([
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => 'orbit-instances', 'size' => $clone ? '40GiB' : '80GiB'],
                'eth0' => ['type' => 'nic', 'name' => 'eth0', 'network' => 'orbitinst0', 'ipv4.address' => $runtime->bridge_address,
                    'security.port_isolation' => 'true', 'security.acls' => 'orbitinst0-proxy,'.$runtime->container_name,
                    'security.acls.default.egress.action' => 'reject', 'security.acls.default.ingress.action' => 'reject',
                    // The NIC accepts only its own IPv4 and MAC address, as create, clone and migrate write it.
                    'security.ipv4_filtering' => 'true', 'security.mac_filtering' => 'true'],
                'orbit-runtime' => ['type' => 'disk', 'source' => '/opt/orbit-runtime', 'path' => '/opt/orbit-runtime', 'readonly' => 'true', 'shift' => 'true'],
                'orbit-agent' => ['type' => 'disk', 'source' => '/opt/orbit-agent', 'path' => '/opt/orbit-agent', 'readonly' => 'true', 'shift' => 'true'],
                ...($clone ? ['t3' => ['type' => 'proxy', 'listen' => 'tcp:10.44.0.7:'.$runtime->t3_port, 'connect' => 'tcp:'.$runtime->bridge_address.':3773', 'nat' => 'true']] : []),
            ]);
    })->with([false, true]);

    // docs/reference/development-containers.md#instance-host-in-the-fleet: an idmapped mount fails a third level below the host.
    it('admits the shared folders unshifted only on the Instance fleet Node, and idmapped on every other Node', function (string $folder): void {
        $marker = sys_get_temp_dir().'/orbit-instance-fleet-devices-'.getmypid();
        config(['instances.fleet_marker' => $marker]);
        $project = containerDefaultProject();
        $real = recordedNestedInstance($project, containerDefaultNode());
        $fleet = recordedNestedInstance($project, containerDefaultNode('app-dev', NestedFleetLayout::Layout['app-dev']['address']), 'feature');
        $device = static fn (Instance $instance): array => app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail())['allowed_devices'][$folder];
        $path = $folder === 'orbit-runtime' ? '/opt/orbit-runtime' : '/opt/orbit-agent';
        $idmapped = ['type' => 'disk', 'source' => $path, 'path' => $path, 'readonly' => 'true', 'shift' => 'true'];
        touch($marker);
        try {
            expect($device($fleet))->toBe([...$idmapped, 'shift' => 'false'])
                ->and($device($real))->toBe($idmapped);
        } finally {
            unlink($marker);
        }
        // Without the fleet marker the same Node is a real Node.
        expect($device($fleet))->toBe($idmapped);
    })->with(['orbit-runtime', 'orbit-agent']);
});

describe('the root disk size', function (): void {
    it('gives a nested-fleet default the setting\'s size, and a copy and a normal default 40GiB', function (): void {
        config(['instances.nested_default_root_size' => '120GiB']);
        $node = containerDefaultNode();
        $default = recordedNestedInstance(containerDefaultProject(), $node);
        $default->runtime()->firstOrFail()->update(['seed_commit' => str_repeat('a', 40)]);
        $copy = recordedNestedInstance($default->project, $node, 'feature', $default);
        $normal = Instance::query()->create(['project_id' => otherProject('normal')->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal/default']);
        app(InstanceContainer::class)->reserve($normal, $node);
        $sizes = array_map(static function (Instance $instance): array {
            $intent = app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail());

            return [$intent['root_disk_size'], $intent['allowed_devices']['root']['size'] ?? null];
        }, [$default, $copy, $normal]);

        expect($sizes)->toBe([['120GiB', '120GiB'], ['40GiB', '40GiB'], ['40GiB', null]]);
    });

    it('refuses a setting that is not a GiB size of at least 40GiB', function (mixed $size): void {
        config(['instances.nested_default_root_size' => $size]);
        $instance = recordedNestedInstance(containerDefaultProject(), containerDefaultNode());

        expect(fn () => app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail()))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.nested_root_size_invalid'));
    })->with(['39GiB', '80GB', '080GiB', '80 GiB', '', null, 80]);
});

describe('the inner volume', function (): void {
    it('is recorded only for a new nested-fleet default, and its intent, start guard and removal name it', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $project = otherProject('nested', true);
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/nested/default']);
        $runtime = app(InstanceContainer::class)->reserve($default, $node);
        $intent = app(InstanceContainer::class)->intent($default, $runtime);

        expect($runtime->incus_project)->toBe('orbit-instances-nested')
            ->and($runtime->inner_volume)->toBe('inner-'.$default->id)
            ->and($intent['inner_volume'])->toBe(['name' => 'inner-'.$default->id, 'pool' => 'orbit-instances', 'size' => '30GiB',
                'config' => ['zfs.block_mode' => 'true', 'block.filesystem' => 'btrfs', 'block.mount_options' => 'user_subvol_rm_allowed,discard', 'security.shifted' => 'true'],
                'device' => 'inner-pool', 'path' => '/srv/inner-pool', 'inner_pool' => ['name' => 'inner', 'source' => '/srv/inner-pool/pool']])
            ->and($intent['allowed_devices']['inner-pool'])->toBe(['type' => 'disk', 'pool' => 'orbit-instances', 'source' => 'inner-'.$default->id, 'path' => '/srv/inner-pool'])
            ->and(app(ContainerInstanceDestroy::class)->capture($default)['inner_volume'])->toBe('inner-'.$default->id);

        // A clone of it records its own volume, which the clone copies from the default's seed
        // (docs/reference/development-containers.md#copies-of-the-volume).
        $clone = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/nested/feature']);
        expect(app(InstanceContainer::class)->reserve($clone, $node, $default)->inner_volume)->toBe('inner-'.$clone->id);

        // A non-default runtime with no clone source, as a migration reserves one, records none either.
        $moved = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'moved', 'checkout_path' => '/test/nested/moved']);
        expect(app(InstanceContainer::class)->reserve($moved, $node)->inner_volume)->toBeNull();
    });

    it('is absent for a normal default and for a nested runtime that records none, as Instance 2454', function (string $shape): void {
        $node = containerDefaultNode();
        $instance = $shape === 'normal'
            ? Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default'])
            : recordedNestedInstance(containerDefaultProject(), $node);
        $runtime = $shape === 'normal' ? app(InstanceContainer::class)->reserve($instance, $node) : $instance->runtime()->firstOrFail();
        $intent = app(InstanceContainer::class)->intent($instance, $runtime);

        expect($runtime->inner_volume)->toBeNull()
            ->and($intent)->not->toHaveKey('inner_volume')
            ->and($intent['allowed_devices'] ?? [])->not->toHaveKey('inner-pool')
            ->and(app(ContainerInstanceDestroy::class)->capture($instance))->not->toHaveKey('inner_volume');
    })->with(['normal', 'nested without a volume']);
});

describe('a normal Node and a normal Instance stay byte-identical to main', function (): void {
    it('keeps the full host intent of a normal Node with the switch absent or off', function (string $switch, bool $taskContainers): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        $settings = [...$node->settings, 't3' => ['connection_id' => 'fixture']];
        if ($taskContainers) {
            $settings['task_vms'] = taskVms(1, 1);
        }
        if ($switch === 'off') {
            $settings['nested_fleet'] = false;
        }
        $node->update(['settings' => $settings]);
        // A normal Instance runtime on the Node changes nothing either.
        app(InstanceContainer::class)->reserve(Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id,
            'name' => 'default', 'checkout_path' => '/test/default']), $node->refresh());
        $expected = app(BaselineNormalInstanceHostIntent::class)->for($node);
        $actual = app(InstanceHostIntent::class)->for($node);
        // `own_site` came after the frozen copy: every Node gets it alike, and ContainerDevPortsTest pins it. So did `machine_names`,
        // the presented name of each container for the converge's files; ContainerCloneInstanceTest pins it.
        $default = Instance::query()->where('name', 'default')->sole();
        expect($actual['machine_names'] ?? null)->toBe([['project' => 'orbit-instances', 'container' => 'instance-'.$default->id,
            ...InstanceMachineName::intent($default)]])
            // So did `t3_files`; a default runs no T3, and InstanceHostIntentTest pins a clone's.
            ->and($actual['t3_files'] ?? null)->toBe([])
            ->and(json_encode(array_diff_key($actual ?? [], ['own_site' => true, 'machine_names' => true, 't3_files' => true]), JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR))
            ->and($actual)->not->toHaveKeys(['nested', 'runtime_projects']);
    })->with(['absent', 'off'])->with([false, true]);

    it('keeps the full default and clone intents of a normal Instance', function (bool $clone): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        nestedSwitch($node, true);
        $project = containerDefaultProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        $containers = app(InstanceContainer::class);
        $runtime = $containers->reserve($instance, $node);
        if ($clone) {
            $runtime->update(['seed_commit' => str_repeat('a', 40)]);
            $source = $instance;
            $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
            $runtime = $containers->reserve($instance, $node, $source);
        }
        $expected = normalContainerSinceMain($instance, $runtime);
        if ($clone) {
            // The one change since main: every clone carries the files a seed may lack, before `files` (InstanceContainer::bootFiles).
            $actual = $containers->intent($instance, $runtime);
            $files = array_search('files', array_keys($expected['clone']), true);
            $expected['clone'] = [...array_slice($expected['clone'], 0, $files, true), 'boot_files' => $actual['clone']['boot_files'],
                ...array_slice($expected['clone'], $files, null, true)];
            // The second change: T3 starts with the copy (development-containers.md#t3-starts-with-the-copy). The unit's condition file,
            // the model environment's frame line, and the files that go in before the first start.
            $expected['clone']['t3']['condition'] = '/etc/orbit/instance';
            $expected['clone']['model_environment']['frame'] = '#orbit-model-environment';
            foreach ($expected['clone']['files'] as $index => $file) {
                $expected['clone']['files'][$index] = ['path' => $file['path'], 'before_start' => true, ...array_diff_key($file, ['path' => true])];
            }
            // The third change: T3's drop-in, so its start does not wait for `sysinit.target` (development-containers.md#t3-starts-with-the-copy).
            $expected['clone']['files'][] = ['path' => InstanceContainer::T3BootDropIn, 'before_start' => true, 'uid' => 0, 'gid' => 0, 'mode' => '0644',
                'content' => "# Managed by Orbit.\n[Unit]\nDefaultDependencies=no\nAfter=nftables.service local-fs.target systemd-journald.socket systemd-sysctl.service\nConflicts=shutdown.target\nBefore=shutdown.target\n"];
            // The fourth change: the drop-in that names the runtime's container server (development-containers.md#container-server).
            $expected['clone']['files'][] = ['path' => InstanceContainer::ServerDropIn, 'before_start' => true, 'uid' => 0, 'gid' => 0, 'mode' => '0644',
                'content' => "# Managed by Orbit.\n[Service]\nExecStart=\nExecStart=/opt/orbit-agent/current/agent/bin/t3 serve --host=0.0.0.0 --port=3773 --no-browser\n"];
            // The fifth change: a clone's agents read its own agent folder, which its create fills (development-containers.md#agent-instructions).
            $expected['clone']['links'] = InstanceAgentInstructions::links();
            expect(array_column($actual['clone']['files'], 'before_start', 'path'))->toBe(['/etc/orbit/instance' => true,
                '/home/agent/.codex/config.toml' => true, '/home/agent/.claude.json' => true, '/home/agent/.t3/userdata/settings.json' => true, InstanceContainer::T3BootDropIn => true,
                InstanceContainer::ServerDropIn => true]);
            expect(array_column($actual['clone']['boot_files'], 'path'))
                ->toBe(['/etc/ld.so.conf.d/zz-orbit-browser.conf', '/etc/fonts/fonts.conf', '/etc/gitconfig', '/etc/sudoers.d/orbit-agent', '/etc/ld.so.cache', '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
                    '/home/agent/.pki/nssdb/pkcs11.txt', '/etc/systemd/system.conf.d/91-orbit-browsers.conf', '/etc/profile.d/91-orbit-browsers.sh',
                    // The presented machine name that T3's first start reads (InstanceMachineName::bootFile).
                    '/etc/machine-info']);
        }
        expect(json_encode($containers->intent($instance, $runtime), JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR));
    })->with([false, true]);

    it('keeps the full intent of an Instance with a database server and no websocket app, while another Instance holds one', function (): void {
        $this->freezeTime();
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        $containers = app(InstanceContainer::class);
        $runtime = $containers->reserve($instance, $node);
        $services = containerDefaultNode('Services', '10.44.0.3', false);
        $services->roles()->create(['role' => RoleName::WebSocket, 'status' => 'active']);
        $server = DatabaseServer::query()->create(['slug' => 'catalog', 'driver' => 'pgsql', 'node_id' => $services->id, 'tag' => '18',
            'port' => 5434, 'root_password' => 'root-sentinel', 'status' => 'active']);
        $connection = DatabaseConnection::query()->forceCreate(['slug' => 'catalog', 'driver' => 'pgsql', 'node_id' => $services->id,
            'database_server_id' => $server->id, 'owner_instance_id' => $instance->id, 'host' => '10.44.0.3', 'port' => 5434,
            'database' => 'catalog', 'username' => 'catalog_reader', 'password' => 'catalog-secret']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $instance->id, 'database_connection_id' => $connection->id, 'prefix' => 'DB', 'network' => 'wireguard']);
        // Another Instance's private app opens nothing for this one.
        $other = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'other', 'checkout_path' => '/test/other']);
        $app = WebSocketApp::query()->create(['name' => 'tool', 'app_id' => '1001', 'key' => 'reverb-key', 'secret' => 'reverb-secret', 'credential_generation' => 1]);
        WebSocketAppTarget::query()->create(['websocket_app_id' => $app->id, 'instance_id' => $other->id]);

        $intent = $containers->intent($instance, $runtime);
        expect(json_encode($intent, JSON_THROW_ON_ERROR))
            ->toBe(json_encode(normalContainerSinceMain($instance, $runtime), JSON_THROW_ON_ERROR))
            ->and($intent)->not->toHaveKey('private_names')
            ->and(array_column($intent['bridge_endpoints'], 'port'))->toBe([5434]);
    });

    it('admits a normal default create with the same runtime, workspace path and captured container intent', function (): void {
        SwampNodeFixture::ensure();
        $this->freezeTime();
        $node = nestedSwitch(containerDefaultNode(), true);
        $operation = containerCreate(containerDefaultProject(), $node);
        $instance = Instance::query()->sole();
        $runtime = InstanceRuntime::query()->sole();
        // The create path adds its `steps` beside the container intent.
        $captured = containerIntentOf($operation);
        unset($captured['steps']);
        expect($runtime->incus_project)->toBe('orbit-instances')
            ->and($instance->checkout_path)->toBe('/var/lib/incus/storage-pools/orbit-instances/containers/orbit-instances_instance-'.$instance->id.'/rootfs/srv/workspace')
            ->and(json_encode($captured, JSON_THROW_ON_ERROR))
            ->toBe(json_encode(normalContainerSinceMain($instance, $runtime), JSON_THROW_ON_ERROR));
    });
});

/**
 * The frozen baseline with the intended changes since it: a default's deploy steps put Playwright's browsers beside its package
 * and agent-browser opens the agent folder's headless Chrome (InstanceContainer::browserEnvironment), the machine name and the power helper's web port.
 *
 * @return array<string, mixed>
 */
function normalContainerSinceMain(Instance $instance, InstanceRuntime $runtime): array
{
    $expected = app(BaselineNormalInstanceContainer::class)->intent($instance, $runtime);
    $expected['environment'] = [...$expected['environment'], 'PLAYWRIGHT_BROWSERS_PATH=0', 'AGENT_BROWSER_EXECUTABLE_PATH=/opt/orbit-agent/current/agent/chrome-headless-shell/chrome-headless-shell-linux64/chrome-headless-shell'];
    // The presented machine name came after main, right after the Incus name (InstanceMachineName).
    $name = array_search('name', array_keys($expected), true) + 1;
    $expected = [...array_slice($expected, 0, $name, true), 'machine_name' => InstanceMachineName::intent($instance), ...array_slice($expected, $name, null, true)];
    // The power helper's readiness port came with prepared restore, right after the web config (docs/reference/prepared-restore.md).
    $web = array_search('web_config', array_keys($expected), true) + 1;
    $expected = [...array_slice($expected, 0, $web, true), 'web_port' => $expected['web_config'] === null ? null : InstanceContainer::WebPort, ...array_slice($expected, $web, null, true)];
    // The CLI-only PHP ini came after main, right after `php`; a Project without PHP has none (InstanceContainer::phpCliIni).
    $php = array_search('php', array_keys($expected), true) + 1;
    $expected = [...array_slice($expected, 0, $php, true), 'php_cli_ini' => null, ...array_slice($expected, $php, null, true)];

    return $expected;
}

describe('a switched-on Node', function (): void {
    it('prepares the nested project and keeps two spare maps; recorded nested runtimes add one each, on their Node only', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $other = nestedSwitch(containerDefaultNode('shark', '10.44.0.8'), true);
        $host = app(InstanceHostIntent::class)->for($node);
        expect(array_keys($host['nested']))->toBe(['project', 'image'])
            ->and($host['nested']['project'])->toBe('orbit-instances-nested')
            ->and($host['nested']['image']['alias'])->toStartWith('orbit-instance-nested-')
            ->and($host['project'])->toBe('orbit-instances')
            ->and($host['ids'])->toBe(13_172_736 + 2 * 101_000_000)
            ->and($host)->not->toHaveKey('runtime_projects');
        recordedNestedInstance(containerDefaultProject(), $node);
        recordedNestedInstance(otherProject('second'), $node);
        expect(app(InstanceHostIntent::class)->for($node)['ids'])->toBe(13_172_736 + 4 * 101_000_000)
            ->and(app(InstanceHostIntent::class)->for($other)['ids'])->toBe(13_172_736 + 2 * 101_000_000);
    });

    it('counts no ID maps for the task container places of a task_vms key in a Node\'s settings', function (): void {
        $node = containerDefaultNode();
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true,
            'task_vms' => taskVms(7, 5)]]);
        expect(app(InstanceHostIntent::class)->for($node->refresh())['ids'])->toBe(13_172_736 + 2 * 101_000_000);
    });

    it('counts a Node\'s CI places from settings.ci, and no stored task container places', function (bool $containers, int $places): void {
        $node = containerDefaultNode();
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true, 'ci' => ['places' => 3, 'proof_only' => false],
            ...($containers ? ['task_vms' => taskVms(7, 5)] : [])]]);
        expect(app(InstanceHostIntent::class)->for($node->refresh())['ids'])->toBe(13_172_736 + ($places + 1) * 101_000_000 + 2 * 101_000_000);
    })->with([
        'CI places only' => [false, 3],
        'CI and stored task container places' => [true, 3],
    ]);

    it('keeps recorded nested runtimes in the ID demand and the folder refresh after the switch is off', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        recordedNestedInstance(containerDefaultProject(), $node);
        $host = app(InstanceHostIntent::class)->for(nestedSwitch($node, false));
        expect($host)->not->toHaveKey('nested')
            ->and($host['runtime_projects'])->toBe(['orbit-instances', 'orbit-instances-nested'])
            ->and($host['ids'])->toBe(13_172_736 + 101_000_000);
    });
});

it('validates a captured container Schedule run in either recorded project against the production workflow schema', function (bool $nested): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = $nested ? recordedNestedInstance($project, $node) : Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'default', 'checkout_path' => '/test/default']);
    $instance->update(['status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false]);
    $runtime = $instance->runtime()->first() ?? app(InstanceContainer::class)->reserve($instance, $node);
    $schedule = Schedule::query()->create(['target_type' => 'instance', 'target_id' => $instance->id, 'host_node_id' => $node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 30, 'trigger' => 'swamp', 'desired_timer_state' => 'enabled', 'status' => 'active']);
    SwampNodeFixture::ensure();
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame): array {
        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'fixture']);
    $input = app(DirectOperation::class)->workflowInputs(app(RunScheduleAction::class)->admit($schedule));
    expect($input['intent']['container'])->toBe(['project' => $runtime->refresh()->incus_project, 'name' => $runtime->container_name]);
    $workflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-task-command-run.yaml'));
    $validate = new Process(['deno', 'eval', <<<'JS'
import Ajv from "npm:ajv@8.17.1";
import addFormats from "npm:ajv-formats@3.0.1";
const {schema, input} = JSON.parse(await new Response(Deno.stdin.readable).text());
const ajv = new Ajv({strict: false});
addFormats(ajv);
if (!ajv.validate(schema, input)) throw new Error(JSON.stringify(ajv.errors));
JS]);
    $validate->setInput(json_encode(['schema' => $workflow['inputs'], 'input' => $input], JSON_THROW_ON_ERROR));
    $validate->run();
    expect($validate->isSuccessful())->toBeTrue($validate->getErrorOutput())
        ->and($workflow['jobs'][0]['steps'][0]['task']['inputs']['argv'])->toContain('inputs.intent.container.project');
})->with([false, true]);

describe('the id map size', function (): void {
    it('records the full size for a new nested default and its copies, the converted size for copies made after the conversion, and none for a normal container', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $project = otherProject('sized', true);
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/sized/default']);
        $runtime = app(InstanceContainer::class)->reserve($default, $node);
        $old = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'old', 'checkout_path' => '/test/sized/old']);
        $oldRuntime = app(InstanceContainer::class)->reserve($old, $node, $default);
        expect($runtime->idmap_size)->toBe(101000000)->and($oldRuntime->idmap_size)->toBe(101000000)
            ->and(app(InstanceContainer::class)->intent($default, $runtime)['config']['security.idmap.size'])->toBe('101000000');

        // docs/reference/development-containers.md#convert-a-default: the record follows the operator's change on the Node and clears the seed.
        $runtime->update(['seed_commit' => str_repeat('a', 40)]);
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $default->id, 'size' => '1165536'])->assertSuccessful();
        $runtime->refresh();
        expect($runtime->idmap_size)->toBe(1165536)->and($runtime->seed_commit)->toBeNull()
            ->and(app(InstanceContainer::class)->intent($default, $runtime)['config']['security.idmap.size'])->toBe('1165536');

        // After the next passed deployment's seed, a copy takes the converted size; the older copy keeps its own, so its start guard still matches.
        $runtime->update(['seed_commit' => str_repeat('b', 40)]);
        $new = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'new', 'checkout_path' => '/test/sized/new']);
        $newRuntime = app(InstanceContainer::class)->reserve($new, $node, $default);
        expect($newRuntime->idmap_size)->toBe(1165536)
            ->and(app(InstanceContainer::class)->intent($new, $newRuntime)['config']['security.idmap.size'])->toBe('1165536')
            ->and($oldRuntime->fresh()?->idmap_size)->toBe(101000000)
            ->and(app(InstanceContainer::class)->intent($old, $oldRuntime->fresh())['config']['security.idmap.size'])->toBe('101000000');

        $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
        $normalRuntime = app(InstanceContainer::class)->reserve($normal, $node);
        expect($normalRuntime->idmap_size)->toBeNull()
            ->and(app(InstanceContainer::class)->intent($normal, $normalRuntime)['config'])->not->toHaveKey('security.idmap.size');
    });

    it('counts each nested container\'s recorded size in the Node\'s subordinate ID demand', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $before = app(InstanceHostIntent::class)->for($node)['ids'];
        $default = recordedNestedInstance(otherProject('demand', true), $node);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
        recordedNestedInstance($default->project, $node, 'feature', $default);
        recordedNestedInstance(otherProject('legacy', true), $node);

        expect(app(InstanceHostIntent::class)->for($node)['ids'] - $before)->toBe(2 * 1165536 + 101000000);
    });

    it('refuses a size, Instance or moment the conversion record does not fit, and changes nothing', function (string $case, string $code): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $default = recordedNestedInstance(otherProject('refused', true), $node);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('a', 40)]);
        $target = match ($case) {
            'a clone' => recordedNestedInstance($default->project, $node, 'feature', $default),
            'a normal default' => (function () use ($node): Instance {
                $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
                app(InstanceContainer::class)->reserve($normal, $node);

                return $normal;
            })(),
            default => $default,
        };
        if ($case === 'a raise over a small copy') {
            InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
            recordedNestedInstance($default->project, $node, 'small', $default);
        }
        if ($case === 'an open Operation') {
            DB::table('operations')->insert(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $node->id,
                'status' => 'running', 'input' => '{}', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $size = match ($case) {
            'another size' => '65536',
            'a raise over a small copy' => '2000000',
            default => '1165536',
        };
        // The size refusal names the floor: the inner range is the outer size minus 1,000,000 (development-containers.md#the-id-map-size).
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $target->id, 'size' => $size])
            ->expectsOutputToContain($case === 'another size' ? $code.': The size must be 2000000 or 1165536. The inner range is the outer size minus 1,000,000, so no size below 1,065,536 works.' : $code)
            ->assertFailed();
        expect(InstanceRuntime::query()->where('instance_id', $default->id)->first(['idmap_size', 'seed_commit'])?->only(['idmap_size', 'seed_commit']))
            ->toBe(['idmap_size' => $case === 'a raise over a small copy' ? 1165536 : 101000000, 'seed_commit' => str_repeat('a', 40)]);
    })->with([
        'another size' => ['another size', 'instance.idmap_size_invalid'],
        'a clone' => ['a clone', 'instance.idmap_size_not_nested_default'],
        'a normal default' => ['a normal default', 'instance.idmap_size_not_nested_default'],
        'an open Operation' => ['an open Operation', 'operation.target_busy'],
        'a raise over a small copy' => ['a raise over a small copy', 'instance.idmap_size_copies_smaller'],
    ]);

    // docs/reference/development-containers.md#raise-a-default-to-2000000: 2,000,000 gives nested-app-dev room for Instances, and 1,165,536 is its rollback.
    it('raises a converted default to 2,000,000 and rolls it back to 1,165,536, and its next copy takes the raised size', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $default = recordedNestedInstance(otherProject('raised', true), $node);
        $runtime = InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail();
        $runtime->update(['idmap_size' => 1165536, 'seed_commit' => str_repeat('a', 40)]);

        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $default->id, 'size' => '2000000'])->assertSuccessful();
        expect($runtime->refresh()->only(['idmap_size', 'seed_commit']))->toBe(['idmap_size' => 2000000, 'seed_commit' => null]);
        $runtime->update(['seed_commit' => str_repeat('b', 40)]);
        $copy = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $node->id, 'name' => 'raised-copy', 'checkout_path' => '/test/raised/copy']);
        $copyRuntime = app(InstanceContainer::class)->reserve($copy, $node, $default);
        expect($copyRuntime->idmap_size)->toBe(2000000)
            ->and(app(InstanceContainer::class)->intent($copy, $copyRuntime)['config']['security.idmap.size'])->toBe('2000000');

        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $default->id, 'size' => '1165536'])->assertSuccessful();
        expect($runtime->refresh()->idmap_size)->toBe(1165536)->and($copyRuntime->fresh()?->idmap_size)->toBe(2000000);
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $default->id, 'size' => '101000000'])->assertFailed();
    });

    it('warns in Doctor from the kept ID map read while a full-size nested container is on the Node, and not after its conversion', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        // Doctor observes only a Node whose SSH management is verified.
        DB::table('nodes')->where('id', $node->id)->update(['ssh_host_fingerprint' => 'SHA256:test']);
        $default = recordedNestedInstance(otherProject('doctor', true), $node);
        $issues = static fn (): array => array_values(array_filter(app(FactDoctor::class)->report([$node->refresh()], [DoctorFamily::Node])->toArray()['nodes'][0]['families'][0]['issues'],
            static fn (array $issue): bool => $issue['code'] === 'node.id_ranges_low'));
        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 1, 'small' => 10, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        expect($issues())->toHaveCount(1);
        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 2, 'small' => 3, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        expect($issues())->toHaveCount(1)->and($issues()[0]['observed'])->toBe('2 full-size, 3 normal slots free, read at 2026-11-14T13:00:00+00:00');
        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 2, 'small' => 4, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        expect($issues())->toBe([]);

        DB::table('nodes')->where('id', $node->id)->update(['id_ranges' => json_encode(['full' => 0, 'small' => 0, 'read_at' => '2026-11-14T13:00:00+00:00'])]);
        // An old copy keeps the full size but retakes no seed, so only the default's own size counts.
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('a', 40)]);
        $copy = recordedNestedInstance($default->project, $node, 'feature', $default);
        expect($issues())->toHaveCount(1);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
        expect($copy->runtime()->value('idmap_size'))->toBe(101000000)->and($issues())->toBe([]);
    });

    it('fills the full size for every nested runtime that exists when the column comes, and none for a normal one', function (): void {
        $node = nestedSwitch(containerDefaultNode(), true);
        $nested = recordedNestedInstance(otherProject('existing', true), $node);
        $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
        app(InstanceContainer::class)->reserve($normal, $node);
        $migration = require database_path('migrations/2027_02_16_000000_add_idmap_size_to_instance_runtimes.php');
        $migration->down();
        $migration->up();

        expect(InstanceRuntime::query()->where('instance_id', $nested->id)->value('idmap_size'))->toBe(101000000)
            ->and(InstanceRuntime::query()->where('instance_id', $normal->id)->value('idmap_size'))->toBeNull();
    });
});

/**
 * The real start guard (swamp/ansible/instance_network.py with instance_nested.py, composed as instance-network-nic-check.yml composes
 * them) over a container read, with the allowed devices, config and volumes that the Ansible start passes from the intent. A fake
 * `incus` answers the guard's own volume read. Returns the exit code and what the guard named.
 *
 * @param  array<string, mixed>  $intent  the intent's `container`
 * @param  array<string, string>  $config  the container's effective config
 * @param  array<string, array<string, string>>  $devices  the container's effective devices
 * @param  array<string, string>|null  $volume  the custom volume's config on the Node, if any
 * @return array{0: int, 1: string}
 */
function windowGuard(array $intent, array $config, array $devices, ?array $volume): array
{
    $folder = sys_get_temp_dir().'/window-guard-'.bin2hex(random_bytes(4));
    mkdir($folder);
    file_put_contents($folder.'/volume.json', json_encode(['config' => $volume ?? []], JSON_THROW_ON_ERROR));
    file_put_contents($folder.'/incus', "#!/bin/sh\ncase \"\$*\" in *'/volumes/custom/'*) cat '{$folder}/volume.json';; *) echo '{\"config\": {}}';; esac\n");
    chmod($folder.'/incus', 0755);
    $ansible = base_path('../../swamp/ansible');
    $source = explode('if __name__ == "__main__":', (string) file_get_contents($ansible.'/instance_network.py'))[0]
        .file_get_contents($ansible.'/instance_nested.py');
    $volumes = isset($intent['inner_volume'])
        ? [$intent['inner_volume']['device'] => [...$intent['inner_volume']['config'], 'size' => $intent['inner_volume']['size']]] : [];
    $guard = new Process(['python3', '-c', $source, 'orbitinst0', 'eth0', json_encode($intent['allowed_devices'], JSON_THROW_ON_ERROR),
        json_encode($intent['config'], JSON_THROW_ON_ERROR), json_encode((object) $volumes, JSON_THROW_ON_ERROR)],
        null, ['PATH' => $folder.':'.getenv('PATH')]);
    $guard->setInput(json_encode(['name' => $intent['name'], 'project' => $intent['project'],
        'expanded_config' => [...$config, 'volatile.uuid' => 'u', 'image.os' => 'ubuntu'], 'expanded_devices' => $devices], JSON_THROW_ON_ERROR));
    $guard->run();
    array_map(unlink(...), glob($folder.'/*') ?: []);
    rmdir($folder);

    return [(int) $guard->getExitCode(), trim($guard->getOutput())];
}

/**
 * Instance 2454's shape before the window: a nested default with the full size and no inner volume, a seed, and one old copy.
 *
 * @return array{0: Node, 1: Instance, 2: Instance}
 */
function windowDefault(): array
{
    $node = nestedSwitch(containerDefaultNode(), true);
    $default = recordedNestedInstance(otherProject('window', true), $node);
    InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('a', 40)]);
    $old = recordedNestedInstance($default->project, $node, 'old', $default);

    return [$node, $default, $old];
}

describe('the inner volume of a converted default', function (): void {
    it('records the volume the product would reserve, so the intent, the start guard, the seed and each new clone take the converted shape', function (): void {
        [$node, $default, $old] = windowDefault();
        $containers = app(InstanceContainer::class);
        $volume = 'inner-'.$default->id;
        expect($default->runtime()->value('inner_volume'))->toBeNull()->and($old->runtime()->value('inner_volume'))->toBeNull();

        // The two Gateway lines of the window (docs/reference/development-containers.md#convert-a-default).
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $default->id, 'size' => '1165536'])->assertSuccessful();
        $idmapOnly = $containers->intent($default, $default->runtime()->firstOrFail());
        $guard = ['devices' => ['inner-pool' => ['type' => 'disk', 'pool' => 'orbit-instances', 'source' => $volume, 'path' => '/srv/inner-pool']],
            'volume' => ['project' => 'orbit-instances-nested', 'pool' => 'orbit-instances', 'name' => $volume, 'config' => ['zfs.block_mode' => 'true',
                'block.filesystem' => 'btrfs', 'block.mount_options' => 'user_subvol_rm_allowed,discard', 'security.shifted' => 'true', 'size' => '30GiB']],
            'inner_pool' => ['name' => 'inner', 'source' => '/srv/inner-pool/pool']];
        $this->artisan('gateway:instance-inner-volume', ['instance' => (string) $default->id, 'volume' => $volume])
            ->expectsOutput(json_encode(['instance_id' => $default->id, 'inner_volume' => $volume, 'from' => null, 'seed_commit' => null, 'start_guard' => $guard], JSON_THROW_ON_ERROR))
            ->expectsOutputToContain("incus storage volume show orbit-instances {$volume} --project orbit-instances-nested; incus config device show instance-{$default->id} --project orbit-instances-nested")
            ->assertSuccessful();
        $runtime = $default->runtime()->firstOrFail();

        // (a) The intent carries the inner volume and the small map, as a new nested default's does.
        $intent = $containers->intent($default, $runtime);
        expect($runtime->only(['inner_volume', 'idmap_size', 'seed_commit']))->toBe(['inner_volume' => $volume, 'idmap_size' => 1165536, 'seed_commit' => null])
            ->and($intent['config']['security.idmap.size'])->toBe('1165536')
            ->and($intent['inner_volume'])->toBe(['name' => $volume, 'pool' => 'orbit-instances', 'size' => '30GiB', 'config' => array_diff_key($guard['volume']['config'], ['size' => true]),
                'device' => 'inner-pool', 'path' => '/srv/inner-pool', 'inner_pool' => $guard['inner_pool']])
            ->and($intent['allowed_devices']['inner-pool'])->toBe($guard['devices']['inner-pool']);

        // (b) The start guard admits exactly the converted shape and names each half-converted one.
        $converted = $intent['allowed_devices'];
        $plain = array_diff_key($converted, ['inner-pool' => true]);
        $onNode = $guard['volume']['config'];
        $small = $intent['config'];
        $full = [...$small, 'security.idmap.size' => '101000000'];
        expect(windowGuard($intent, $small, $converted, $onNode))->toBe([0, ''])
            ->and(windowGuard($idmapOnly, $small, $converted, $onNode))->toBe([1, 'inner-pool'])
            ->and(windowGuard($intent, $small, $plain, null))->toBe([1, 'inner-pool'])
            ->and(windowGuard($intent, $full, $converted, $onNode))->toBe([1, 'config security.idmap.size'])
            ->and(windowGuard($intent, $small, $converted, [...$onNode, 'size' => '20GiB']))->toBe([1, 'volume inner-pool size']);

        // (c) Clones refuse until a passed deployment has a new seed; that deployment's intent carries the volume, so it takes the pair.
        expect(placementRefusal(fn () => app(DevelopmentInstanceCreateAdmission::class)->assertSeed($default->refresh())))->toBe('instance.default_missing 409');
        $default->forceFill(['status' => 'active', 'source_layout' => 'checkout'])->save();
        expect(app(InstanceDeploymentIntent::class)->snapshot($default)['container']['inner_volume'])->toBe($intent['inner_volume']);
        $runtime->update(['seed_commit' => str_repeat('b', 40)]);

        // (d) A clone made after it gets its own volume, copied from the default's; the copy made before keeps its own shape.
        $new = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $node->id, 'name' => 'new', 'checkout_path' => '/test/window/new']);
        $newRuntime = $containers->reserve($new, $node, $default->refresh());
        $newIntent = $containers->intent($new, $newRuntime);
        expect($newRuntime->only(['inner_volume', 'idmap_size']))->toBe(['inner_volume' => 'inner-'.$new->id, 'idmap_size' => 1165536])
            ->and($newIntent['inner_volume']['name'])->toBe('inner-'.$new->id)
            ->and($newIntent['allowed_devices']['inner-pool']['source'])->toBe('inner-'.$new->id)
            ->and($newIntent['clone']['inner_volume'])->toBe($volume)
            ->and(windowGuard($newIntent, $newIntent['config'], $newIntent['allowed_devices'], $onNode))->toBe([0, '']);
        $oldRuntime = $old->runtime()->firstOrFail();
        $oldIntent = $containers->intent($old, $oldRuntime);
        expect($oldRuntime->only(['inner_volume', 'idmap_size']))->toBe(['inner_volume' => null, 'idmap_size' => 101000000])
            ->and($oldIntent)->not->toHaveKey('inner_volume')
            ->and($oldIntent['config']['security.idmap.size'])->toBe('101000000')
            ->and(windowGuard($oldIntent, $oldIntent['config'], $oldIntent['allowed_devices'], null))->toBe([0, '']);
    });

    it('clears the record for a rollback, with the seed, once no copy has an inner volume', function (): void {
        [$node, $default] = windowDefault();
        $volume = 'inner-'.$default->id;
        $this->artisan('gateway:instance-inner-volume', ['instance' => (string) $default->id, 'volume' => $volume])->assertSuccessful();
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('b', 40)]);
        // A repeat after the deployment's seed changes nothing and keeps that seed.
        $this->artisan('gateway:instance-inner-volume', ['instance' => (string) $default->id, 'volume' => $volume])
            ->expectsOutputToContain('instance.inner_volume_unchanged')->assertFailed();
        expect(InstanceRuntime::query()->where('instance_id', $default->id)->value('seed_commit'))->toBe(str_repeat('b', 40));
        $copy = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $node->id, 'name' => 'new', 'checkout_path' => '/test/window/new']);
        app(InstanceContainer::class)->reserve($copy, $node, $default->refresh());

        $this->artisan('gateway:instance-inner-volume', ['instance' => (string) $default->id, '--clear' => true])
            ->expectsOutputToContain('instance.inner_volume_copies_with_volume')->assertFailed();
        expect(InstanceRuntime::query()->where('instance_id', $default->id)->first()?->only(['inner_volume', 'seed_commit']))
            ->toBe(['inner_volume' => $volume, 'seed_commit' => str_repeat('b', 40)]);

        InstanceRuntime::query()->where('instance_id', $copy->id)->delete();
        $this->artisan('gateway:instance-inner-volume', ['instance' => (string) $default->id, '--clear' => true])
            ->expectsOutput(json_encode(['instance_id' => $default->id, 'inner_volume' => null, 'from' => $volume, 'seed_commit' => null,
                'start_guard' => ['devices' => [], 'volume' => null, 'inner_pool' => null]], JSON_THROW_ON_ERROR))
            ->expectsOutputToContain('The start guard now refuses a disk inner-pool on instance-'.$default->id)
            ->assertSuccessful();
        $runtime = $default->runtime()->firstOrFail();
        expect($runtime->only(['inner_volume', 'seed_commit']))->toBe(['inner_volume' => null, 'seed_commit' => null])
            ->and(app(InstanceContainer::class)->intent($default, $runtime))->not->toHaveKey('inner_volume');
    });

    it('refuses an Instance, a name or a moment the record does not fit, and changes nothing', function (string $case, string $code): void {
        [$node, $default] = windowDefault();
        $arguments = ['instance' => (string) $default->id, 'volume' => 'inner-'.$default->id];
        match ($case) {
            'both a name and --clear' => $arguments['--clear'] = true,
            'neither a name nor --clear' => $arguments = ['instance' => (string) $default->id],
            'another name' => $arguments['volume'] = 'inner-2454-b',
            'a copy' => $arguments = ['instance' => (string) recordedNestedInstance($default->project, $node, 'feature', $default)->id,
                'volume' => 'inner-'.(Instance::query()->max('id'))],
            'a normal default' => (function () use ($node, &$arguments): void {
                $normal = Instance::query()->create(['project_id' => containerDefaultProject()->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/normal']);
                app(InstanceContainer::class)->reserve($normal, $node);
                $arguments = ['instance' => (string) $normal->id, 'volume' => 'inner-'.$normal->id];
            })(),
            'a default without the nested flag' => $default->project->update(['nested_fleet' => false]),
            '--clear without a record' => $arguments = ['instance' => (string) $default->id, '--clear' => true],
            'an open Operation' => DB::table('operations')->insert(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
                'node_id' => $node->id, 'status' => 'running', 'input' => '{}', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]),
            'an open copy creation' => DB::table('operations')->insert(['kind' => 'instance.create', 'target_type' => 'instance',
                'target_id' => recordedNestedInstance($default->project, $node, 'copying', $default)->id,
                'node_id' => $node->id, 'status' => 'running', 'input' => '{}', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]),
            default => null,
        };
        $this->artisan('gateway:instance-inner-volume', $arguments)
            ->expectsOutputToContain($case === 'another name' ? $code.': The volume of this default is inner-'.$default->id.'.' : $code)
            ->assertFailed();
        expect(InstanceRuntime::query()->whereNotNull('inner_volume')->exists())->toBeFalse()
            ->and(InstanceRuntime::query()->where('instance_id', $default->id)->value('seed_commit'))->toBe(str_repeat('a', 40));
    })->with([
        'both a name and --clear' => ['both a name and --clear', 'instance.inner_volume_arguments_invalid'],
        'neither a name nor --clear' => ['neither a name nor --clear', 'instance.inner_volume_arguments_invalid'],
        'another name' => ['another name', 'instance.inner_volume_name_invalid'],
        'a copy' => ['a copy', 'instance.inner_volume_not_default'],
        'a normal default' => ['a normal default', 'instance.inner_volume_not_nested'],
        'a default without the nested flag' => ['a default without the nested flag', 'instance.inner_volume_nested_flag_off'],
        'an open Operation' => ['an open Operation', 'operation.target_busy'],
        'an open copy creation' => ['an open copy creation', 'instance.clone_in_flight'],
        '--clear without a record' => ['--clear without a record', 'instance.inner_volume_unchanged'],
    ]);
});
