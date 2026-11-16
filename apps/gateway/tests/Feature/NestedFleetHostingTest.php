<?php

declare(strict_types=1);

use App\Actions\Schedules\RunScheduleAction;
use App\Data\Nodes\NodeTaskVmsData;
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
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Proof\ProofSeedCopyProjection;
use App\Domain\Proof\ProofSeeds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCopyStart;
use App\Domain\WireGuard\Ipv4Subnet;
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
function taskVms(int $taskPlaces, int $ciPlaces): array
{
    return NodeTaskVmsData::validated(['cidr' => '10.252.10.0/24', 'task_places' => $taskPlaces, 'ci_places' => $ciPlaces,
        'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 2]], Ipv4Subnet::from('10.44.0.0/24'))->toArray();
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
            ->and($host['create_pool'])->toBeTrue();

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
        ProofSeed::query()->create(['node_id' => $current->id, 'holder' => 'proof-seed-7', 'commit' => str_repeat('a', 40),
            'taken_at' => '2026-11-16 00:00:00', 'operation_id' => 7]);
        // A retake at the same commit is a new seed: the time differs.
        $retaken = ciNode(2, LifecycleStatus::Active, 'ci-old', '10.44.0.37');
        ProofSeed::query()->create(['node_id' => $retaken->id, 'holder' => 'proof-seed-6', 'commit' => str_repeat('a', 40),
            'taken_at' => '2026-11-15 00:00:00', 'operation_id' => 6]);
        ciNode(0, LifecycleStatus::Active, 'ci-none', '10.44.0.34');

        expect(array_map(static fn (Node $node): string => $node->name, app(ProofSeeds::class)->targets()))->toBe(['ci', 'ci-app', 'ci-old'])
            ->and(app(ProofSeeds::class)->holder($current)?->holder)->toBe('proof-seed-7')
            ->and(app(ProofSeeds::class)->holder($retaken))->toBeNull()
            ->and(app(ProofSeeds::class)->holder($behind))->toBeNull();
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

    it('admits one seed copy to the Nodes behind the seed, under the default\'s seed-copy lock, with the push intent', function (): void {
        $default = proofSeedDefault();
        $runtime = $default->runtime()->firstOrFail();
        $runtime->update(['inner_volume' => 'inner-'.$default->id]);
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));

        $operation = app(ProofSeeds::class)->admit('seed');
        expect($operation?->kind)->toBe('instance.seed_copy')
            ->and($operation->target_id)->toBe($default->id)
            ->and($operation->node_id)->toBe($default->node_id)
            ->and($operation->lock_key)->toBe('instance_seed_copy:'.$default->id)
            ->and($operation->input)->toBe(['default_instance_id' => $default->id, 'commit' => str_repeat('a', 40),
                'taken_at' => $runtime->refresh()->seed_taken_at?->toIso8601String(), 'targets' => [$node->id]])
            // One copy at a time: the open copy admits the next one when it ends.
            ->and(app(ProofSeeds::class)->admit('seed'))->toBeNull()
            ->and(Operation::query()->where('kind', 'instance.seed_copy')->count())->toBe(1);

        $intent = app(ProofSeedCopyProjection::class)->input($operation)['intent'];
        expect(collect($intent)->except(['source', 'targets'])->all())->toBe(['project' => 'orbit-instances-nested', 'pool' => 'orbit-instances',
            'snapshot' => 'seed-'.str_repeat('a', 40), 'holder' => 'proof-seed', 'holder_volume' => 'proof-seed-inner', 'holder_nic_acl' => 'orbitinst0-proxy'])
            ->and(collect($intent['source'])->except('ssh_execution')->all())->toBe(['node_id' => $default->node_id,
                'container' => $runtime->container_name, 'inner_volume' => 'inner-'.$default->id])
            ->and(collect($intent['targets'][0])->except('ssh_execution')->all())->toBe(['node_id' => $node->id, 'remote' => 'proof-node-'.$node->id,
                'listen' => '10.44.0.30:8444'])
            ->and($intent['targets'][0]['ssh_execution']['target']['host'])->toBe('10.44.0.30');
    });

    it('records the seed of each passed Node, an Activity problem for each failed one, and admits the next copy for the Nodes still behind', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $passed = ciNode();
        $failed = ciNode(2, LifecycleStatus::Active, 'ci-two', '10.44.0.31');
        foreach ([$passed, $failed] as $node) {
            Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        }
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
        // The end of the copy admits the next one, for the failed Node alone.
        $next = Operation::query()->where('kind', 'instance.seed_copy')->whereKeyNot($operation->id)->sole();
        expect($next->input['targets'])->toBe([$failed->id])
            ->and($next->triggered_by)->toBe('seed_copy');
    });

    it('admits the seed copy when a deployment of the proof default records a new seed', function (): void {
        $default = proofSeedDefault();
        seedSwampNode();
        $node = ciNode();
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $commit = str_repeat('b', 40);
        $deploy = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id,
            'node_id' => $default->node_id, 'status' => 'running', 'triggered_by' => 'release',
            'input' => ['deployment' => app(InstanceDeploymentIntent::class)->snapshot($default)], 'state' => ['swamp_run_id' => 'run-1']]);

        app(InstanceDeployProjection::class)->apply($deploy, ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['steps' => ['Read the container result' => ['stdout' => json_encode(['commit' => $commit], JSON_THROW_ON_ERROR)]],
                'summary' => ['failed_steps' => []]]]);

        $copy = Operation::query()->where('kind', 'instance.seed_copy')->sole();
        expect($copy->input['commit'])->toBe($commit)
            ->and($copy->input['targets'])->toBe([$node->id])
            ->and($copy->triggered_by)->toBe('seed');
    });

    it('admits the seed copy when a ci-runner add of a Node that offers proof places passes', function (): void {
        proofSeedDefault();
        seedSwampNode();
        $node = ciNode(3, LifecycleStatus::Provisioning);
        Node::withoutEvents(fn (): bool => $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
        $assignment = $node->roles()->sole();
        $add = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
            'status' => 'running', 'triggered_by' => 'test', 'state' => ['swamp_run_id' => 'run-1'],
            'input' => ['_role' => ['assignment_id' => $assignment->id, 'role' => 'ci-runner', 'created' => true, 'certificates' => ['leaves' => []]],
                'native_role' => ['runtime' => [], 'publications' => [], 'artifact_digests' => []]]]);

        app(NodeRoleDirectProjection::class)->apply($add, ['run_id' => 'run-1', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['steps' => ['Check the role' => ['status' => 'succeeded']], 'summary' => ['failed_steps' => []]]]);

        expect($assignment->refresh()->status)->toBe(LifecycleStatus::Active)
            ->and(Operation::query()->where('kind', 'instance.seed_copy')->sole()->input['targets'])->toBe([$node->id]);
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

        ProofSeed::query()->create(['node_id' => $node->id, 'holder' => 'proof-seed-9', 'commit' => $commit, 'taken_at' => '2026-11-16 00:00:00', 'operation_id' => 9]);
        expect(TaskCopyStart::seed($runtime, $node))->toBe(['source' => 'proof-seed-9', 'snapshot' => 'seed-'.$commit,
            'seed_commit' => $commit, 'inner_volume' => 'proof-seed-9-inner']);

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
            ->and(json_encode(array_diff_key($actual ?? [], ['own_site' => true, 'machine_names' => true]), JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR))
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
                ->toBe(['/etc/ld.so.conf.d/zz-orbit-browser.conf', '/etc/fonts/fonts.conf', '/etc/gitconfig', '/etc/ld.so.cache', '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
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
 * The frozen baseline with the one intended change since it: a default's deploy steps put Playwright's browsers beside its package
 * (InstanceContainer::BrowserEnvironment).
 *
 * @return array<string, mixed>
 */
function normalContainerSinceMain(Instance $instance, InstanceRuntime $runtime): array
{
    $expected = app(BaselineNormalInstanceContainer::class)->intent($instance, $runtime);
    $expected['environment'][] = 'PLAYWRIGHT_BROWSERS_PATH=0';
    // The presented machine name came after main, right after the Incus name (InstanceMachineName).
    $name = array_search('name', array_keys($expected), true) + 1;
    $expected = [...array_slice($expected, 0, $name, true), 'machine_name' => InstanceMachineName::intent($instance), ...array_slice($expected, $name, null, true)];
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

    it('counts task container places from settings.task_vms.containers, never the VM places', function (): void {
        $node = containerDefaultNode();
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true,
            'task_vms' => taskVms(7, 5)]]);
        expect(app(InstanceHostIntent::class)->for($node->refresh())['ids'])->toBe(13_172_736 + (2 + 1) * 101_000_000 + 2 * 101_000_000);
    });

    it('counts a Node\'s CI places from settings.ci, with or without task container places', function (bool $containers, int $places): void {
        $node = containerDefaultNode();
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true, 'ci' => ['places' => 3, 'proof_only' => false],
            ...($containers ? ['task_vms' => taskVms(7, 5)] : [])]]);
        expect(app(InstanceHostIntent::class)->for($node->refresh())['ids'])->toBe(13_172_736 + ($places + 1) * 101_000_000 + 2 * 101_000_000);
    })->with([
        'CI places only' => [false, 3],
        'CI and task container places' => [true, 3 + 2],
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
        if ($case === 'a rollback under a small copy') {
            InstanceRuntime::query()->where('instance_id', $default->id)->update(['idmap_size' => 1165536]);
            recordedNestedInstance($default->project, $node, 'small', $default);
        }
        if ($case === 'an open Operation') {
            DB::table('operations')->insert(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $node->id,
                'status' => 'running', 'input' => '{}', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $size = match ($case) {
            'another size' => '65536',
            'a rollback under a small copy' => '101000000',
            default => '1165536',
        };
        // The size refusal names the floor: the inner range is the outer size minus 1,000,000 (development-containers.md#the-id-map-size).
        $this->artisan('gateway:instance-idmap-size', ['instance' => (string) $target->id, 'size' => $size])
            ->expectsOutputToContain($case === 'another size' ? $code.': The size must be 1165536 or 101000000. The inner range is the outer size minus 1,000,000, so no size below 1,065,536 works.' : $code)
            ->assertFailed();
        expect(InstanceRuntime::query()->where('instance_id', $default->id)->first(['idmap_size', 'seed_commit'])?->only(['idmap_size', 'seed_commit']))
            ->toBe(['idmap_size' => $case === 'a rollback under a small copy' ? 1165536 : 101000000, 'seed_commit' => str_repeat('a', 40)]);
    })->with([
        'another size' => ['another size', 'instance.idmap_size_invalid'],
        'a clone' => ['a clone', 'instance.idmap_size_not_nested_default'],
        'a normal default' => ['a normal default', 'instance.idmap_size_not_nested_default'],
        'an open Operation' => ['an open Operation', 'operation.target_busy'],
        'a rollback under a small copy' => ['a rollback under a small copy', 'instance.idmap_size_copies_smaller'],
    ]);

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
