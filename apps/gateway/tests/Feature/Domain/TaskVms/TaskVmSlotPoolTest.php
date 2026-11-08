<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\StartTaskVmResumeAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmConvergeInput;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmNestedFleet;
use App\Domain\TaskVms\TaskVmPromotedImage;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotIdentity;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

uses(LazilyRefreshDatabase::class);

/** A task VM host of `$maxVms` slots, with a base snapshot unless `$snapshot` is false. */
function slotPoolHost(int $maxVms = 9, bool $snapshot = true): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $maxVms, 4, '8GiB', '20GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $base = $snapshot ? ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => 'r20261107120000']
        : ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('b', 64)];
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => $base]]);

    return [$host, $project, $build];
}

/** A todo task group of the Project. */
function slotPoolGroup(Project $project): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
}

/** The Gateway, Swamp and VPN Node a release intent names, and a Swamp dispatch that starts nothing unless `$dispatch` is false. */
function slotPoolSweepWorld(bool $dispatch = true): void
{
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:gateway', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-public-key', 'wireguard_public_key' => str_repeat('g', 43).'=']));
    foreach ([RoleName::Gateway, RoleName::Swamp, RoleName::Vpn] as $role) {
        $gateway->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
    }
    if ($dispatch) {
        app()->instance(SwampDispatch::class, new class
        {
            public function start(Operation $operation): void {}
        });
    }
}

/** A Swamp socket that records every vault put, so that a test reads what the Gateway wrote for a run. */
function slotPoolSocket(): object
{
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }

        /** The value the Gateway put under the vault key. */
        public function put(string $key): string
        {
            foreach ($this->frames as $frame) {
                if ($frame['payload']['key'] === $key) {
                    return $frame['payload']['value'];
                }
            }

            throw new RuntimeException("Nothing was put under [{$key}].");
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

it('takes the lowest free slot number and mints a fresh identity, its Node active with the public keys pinned', function (): void {
    [$host, $project] = slotPoolHost();
    $pool = app(TaskVmSlotPool::class);

    $first = $pool->take(slotPoolGroup($project));
    $second = $pool->take(slotPoolGroup($project));

    expect($first->name)->toBe('beast-tvm-2')->and($first->slot_number)->toBe(2)->and($first->state)->toBe(TaskVmState::Provisioning)
        ->and($second->name)->toBe('beast-tvm-3')->and($second->wireguard_ip)->not->toBe($first->wireguard_ip);
    $node = $first->node;
    expect($node)->toBeInstanceOf(Node::class)->and($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->agent_secret_hash)->toBe(hash('sha256', (string) $first->agent_secret))
        ->and($node->wireguard_public_key)->toBe(TaskVmSlotIdentity::wireguardPublicKey((string) $first->wireguard_private_key))
        ->and($node->ssh_host_fingerprint)->toBe(TaskVmSlotIdentity::sshFingerprint((string) $first->ssh_host_private_key))
        ->and($node->roles()->where('role', RoleName::AppDev)->sole()->status)->toBe(LifecycleStatus::Active)
        // The run of a task VM carries its pin; the pin admits no publication of the workers' known hosts.
        ->and(Operation::query()->where('kind', 'node.worker_known_hosts')->exists())->toBeFalse();

    // A released slot's number is free again; the new identity shares nothing with the old one.
    $old = [$first->wireguard_private_key, $first->agent_secret];
    app(TaskVmSlotReleaseProjection::class)->record($first, true, null);
    $third = $pool->take(slotPoolGroup($project));
    expect($third->slot_number)->toBe(2)->and($third->id)->not->toBe($first->id)
        ->and([$third->wireguard_private_key, $third->agent_secret])->not->toBe($old);
});

it('keeps the reserve free when tasks fill the host', function (): void {
    [, $project] = slotPoolHost(maxVms: 6);
    $pool = app(TaskVmSlotPool::class);
    $pool->take(slotPoolGroup($project));

    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class, 'outside the CI/refresh reserve');
    $pool->take(slotPoolGroup($project), proof: true);
    expect(fn () => $pool->take(slotPoolGroup($project), proof: true))->toThrow(TaskCapacityException::class);
});

it('starts a group only from a base snapshot, while a refresh takes a slot on its own host before the host has one', function (): void {
    [$host, $project] = slotPoolHost(snapshot: false);
    $pool = app(TaskVmSlotPool::class);

    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class)
        ->and($pool->take(null, $host->id)->group_id)->toBeNull();
});

it('deletes the Node, its workspace Instance and the keys of a released slot', function (): void {
    [, $project] = slotPoolHost();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $node = $slot->node;
    $slot->update(['state' => TaskVmState::Ready]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null);

    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed)->and($slot->group_id)->toBeNull()->and($slot->node_id)->toBeNull()
        ->and($slot->wireguard_private_key)->toBeNull()->and($slot->agent_secret)->toBeNull()
        ->and(Node::query()->whereKey($node->id)->exists())->toBeFalse()->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
});

it('mints keys whose public halves match what the real tools derive', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.201']);
    $keys = app(TaskVmSlotIdentity::class)->ensure($slot);

    expect(TaskVmSlotIdentity::sshPublicKey((string) $keys->ssh_host_private_key))->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
        ->and(TaskVmSlotIdentity::sshFingerprint((string) $keys->ssh_host_private_key))->toStartWith('SHA256:')
        ->and(strlen((string) base64_decode(TaskVmSlotIdentity::wireguardPublicKey((string) $keys->wireguard_private_key))))->toBe(32);
});

it('submits a release without secrets with an empty secret map, which the workflow takes as an object', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $operation = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Queued, 'input' => ['intent' => ['host' => [], 'slot' => []], 'execution_secrets' => []], 'triggered_by' => 'test']);

    expect(json_encode(app(DirectOperation::class)->workflowInputs($operation)['execution_secrets']))->toBe('{}');
});

it('fails a slot whose release start was refused, keeping its Node for the sweep', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $slot->update(['state' => TaskVmState::Releasing, 'group_id' => null]);
    $operation = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused', 'input' => [], 'triggered_by' => 'test']);

    app(TaskVmSlotReleaseProjection::class)->refused($operation);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('swamp.start_refused')
        ->and($slot->node)->toBeInstanceOf(Node::class);
});

it('has the sweep release each slot that no group holds and no Operation works on, and leaves the others', function (): void {
    [$host, $project] = slotPoolHost(maxVms: 12);
    $pool = app(TaskVmSlotPool::class);
    $stale = $pool->take(slotPoolGroup($project));
    $held = $pool->take(slotPoolGroup($project));
    $busy = $pool->take(slotPoolGroup($project));
    $stale->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    $busy->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $busy->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    slotPoolSweepWorld();

    app()->call([new SweepTaskVmHosts, 'handle']);

    $releases = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('triggered_by', 'task-vm-sweep')->get();
    expect($releases->pluck('target_id')->all())->toBe([$stale->id])
        ->and($releases->sole()->input['intent']['hub_peer']['public_key'])->toBe(TaskVmSlotIdentity::wireguardPublicKey((string) $stale->wireguard_private_key))
        ->and($stale->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and($held->refresh()->state)->toBe(TaskVmState::Provisioning)->and($busy->refresh()->state)->toBe(TaskVmState::Failed);
});

it('refuses a group of a Project whose checkout the base does not carry', function (): void {
    slotPoolHost();
    $other = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => '13.x', 'root' => 'public']);

    expect(fn () => app(TaskVmSlotPool::class)->take(slotPoolGroup($other)))->toThrow(TaskCapacityException::class, 'carries only the checkout of Project')
        ->and(TaskVm::query()->count())->toBe(0);
});

it('has the sweep leave a host alone while its refresh is open, and go on past a row whose release cannot be built', function (): void {
    [$host, $project] = slotPoolHost(maxVms: 12);
    $pool = app(TaskVmSlotPool::class);
    $broken = $pool->take(slotPoolGroup($project));
    $stale = $pool->take(slotPoolGroup($project));
    // A row without its Node has no hub peer to remove, so its intent cannot be built.
    $broken->update(['group_id' => null, 'state' => TaskVmState::Failed, 'node_id' => null]);
    $stale->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    slotPoolSweepWorld();
    $refresh = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    app()->call([new SweepTaskVmHosts, 'handle']);
    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();

    $refresh->update(['status' => OperationStatus::Succeeded]);
    app()->call([new SweepTaskVmHosts, 'handle']);
    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->pluck('target_id')->all())->toBe([$stale->id])
        ->and($broken->refresh()->state)->toBe(TaskVmState::Failed);
});

it('leaves task VM Nodes out of the workers\' known hosts and keeps every other pinned Node', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    slotPoolSweepWorld();

    $content = app(SwampWorkerKnownHostsInput::class)->capture()['known_hosts_content'];

    expect(SwampWorkerKnownHostsInput::covers($slot->node))->toBeFalse()->and(SwampWorkerKnownHostsInput::covers($host))->toBeTrue()
        ->and($content)->toContain('10.44.0.7 ssh-ed25519 fixture-public-key')->not->toContain((string) $slot->wireguard_ip.' ');
});

it('unbinds a failed start\'s group from the workspace its release deletes, and its cancel waits for that release, then closes it', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    $release = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    expect(fn () => app(TaskCancelIntent::class)->start($group, 'test', null))->toThrow(ResourceOperationException::class, 'still running');

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null);
    $release->update(['status' => OperationStatus::Succeeded]);
    expect($group->refresh()->taskable_id)->toBeNull();

    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);
    expect($cancel->input['instance_id'])->toBeNull()->and($cancel->input['release'])->toBeNull()
        ->and(app(TaskCancelIntent::class)->input($cancel)['intent']['group_id'])->toBe($group->id);
});

it('lets a cancel admitted before a failed start\'s release still close the group the release unbound, and keeps the slot destroyed', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    // The cancel is admitted while the release job has not started its Operation yet.
    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);
    app(TaskVmSlotReleaseProjection::class)->record($slot->refresh(), true, null);

    expect(app(TaskCancelIntent::class)->input($cancel)['intent']['group_id'])->toBe($group->id);
    app(TaskGroupEnding::class)->failed($cancel->input, 'task_vm.slot_release_failed');
    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed);
});

it('has a task start converge reach its VM over WireGuard with the pinned key of its admission, and the base VM through its host', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $commit = str_repeat('a', 40);
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));

    $start = app(TaskVmConvergeInput::class)->for($host, $commit, 'task-1', $slot->node)['converge'];
    $base = app(TaskVmConvergeInput::class)->for($host, $commit)['converge'];

    expect($start['direct'])->toBeTrue()
        ->and($start['ssh']['target'])->toBe(['host' => $slot->wireguard_ip, 'user' => 'orbit', 'port' => 22,
            'host_keys' => [$slot->node->ssh_host_key_type.' '.$slot->node->ssh_host_key]])
        ->and($base)->not->toHaveKey('direct')->and($base['ssh']['target']['host'])->toBe('')
        // What every task VM shares goes into the base, not into each start's identity.
        ->and($base['boot']['trust'])->toBe(['certificate' => "-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n",
            'control_ip' => '10.44.0.1', 'gateway_ip' => '10.44.0.1', 'pi_port' => app(PiConnection::class)->port()]);
});

it('gives every converge what a base VM without a nested fleet builds it from', function (): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));

    $converge = app(TaskVmConvergeInput::class)->for($host, str_repeat('a', 40))['converge'];
    $fleet = $converge['fleet'];

    expect($converge['nested'])->toBe(['subnet' => '10.232.2.0/24', 'interface' => 'oe-nested'])
        ->and(array_keys($fleet))->toBe(['guests', 'network', 'operator', 'layout', 'proof_network', 'checkout', 'bootstrap'])
        ->and($fleet['guests'])->toBe(['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'])
        ->and($fleet['network'])->toBe($converge['nested']['interface'])
        ->and($fleet['operator'])->toBe(['address' => '10.45.0.10', 'hub' => '10.45.0.1', 'network' => '10.45.0.0/24'])
        ->and($fleet['layout'])->toBe(TaskVmNestedFleet::Layout)
        ->and($fleet['proof_network'])->toBe($converge['nested']['subnet'])
        ->and($fleet['checkout']['path'])->toBe('/home/orbit/orbit')
        ->and($fleet['checkout']['binds']['app-dev'])->toBe(['home-orbit-orbit-apps-gateway-.env.mount', 'home-orbit-orbit-apps-gateway-storage.mount', 'home-orbit-orbit-apps-gateway-bootstrap-cache.mount'])
        ->and(collect($fleet['checkout']['units'])->groupBy('guest')->map->count()->all())->toBe(['nested-gateway' => 6, 'nested-app-dev' => 3]);
    // The run adds the Swamp pin of the recipe, the machine with its host key and the release; the Gateway sends the rest.
    expect(array_keys($fleet['bootstrap']))->toBe(['apt_proxy', 'gateway', 'operator', 'agent_delivery'])
        ->and($fleet['bootstrap']['apt_proxy'])->toBe('http://10.232.2.1:3142')
        ->and($fleet['bootstrap']['gateway'])->toBe(['url' => 'https://10.45.0.1', 'wireguard_ip' => '10.45.0.1', 'wireguard_subnet' => '10.45.0.0/24'])
        ->and($fleet['bootstrap']['operator'])->toBe(['checkout' => $converge['checkout']['path'], 'wireguard_ip' => '10.45.0.10'])
        ->and(json_decode($fleet['bootstrap']['agent_delivery'], true, flags: JSON_THROW_ON_ERROR))->toHaveKeys(['commit', 'version', 'artifacts'])
        ->and(json_encode($fleet))->not->toContain('club');
});

it('writes the guard origin and the one fixed model key into every claim, and a start or release carries no model intent or secret', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld(dispatch: false);
    $services = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:services', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'services-public-key']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    $socket = slotPoolSocket();
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $runs = app(TaskVmSlotRuns::class);
    $pool = app(TaskVmSlotPool::class);
    $first = $pool->take(slotPoolGroup($project));
    $second = $pool->take(slotPoolGroup($project));

    // The identity of a start reads the hub's public key from the Gateway home.
    $home = sys_get_temp_dir().'/orbit-claim-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    try {
        $start = $runs->claim($first, 'task-1', str_repeat('a', 40));
        $other = $runs->claim($second, 'task-2', str_repeat('a', 40));
    } finally {
        File::deleteDirectory($home);
    }

    $file = static function (array $claim) use ($socket): array {
        [$token, $models] = explode("\n", $socket->put($claim['execution_secrets']['claim']['key']), 2);

        return [$token, json_decode($models, true, flags: JSON_THROW_ON_ERROR)['providers']['orbit-task-vm']];
    };
    [$token, $provider] = $file($start);
    [$otherToken, $otherProvider] = $file($other);
    $first->refresh();
    $second->refresh();
    $key = app(AiRouterSecrets::class)->task();

    // The Pi models file holds the guard origin (port 8318, not the router's own 8317) and the fixed key, the same for every VM.
    expect($provider['baseUrl'])->toBe('http://10.44.0.3:8318/v1')->and($provider['apiKey'])->toBe($key)
        ->and($otherProvider['apiKey'])->toBe($key)->and($otherProvider['baseUrl'])->toBe($provider['baseUrl'])
        // The Pi token stays one per claim.
        ->and($token)->toBe($first->pi_token)->and($otherToken)->toBe($second->pi_token)->and($token)->not->toBe($otherToken)
        // No model intent, no model secret and no model vault key: the start never calls the model router.
        ->and($start['intent'])->not->toHaveKey('model')
        ->and(array_keys($start['execution_secrets']))->toBe(['wireguard', 'ssh_host', 'agent_secret', 'claim'])
        ->and(json_encode($start))->not->toContain($key)
        ->and(array_filter(array_column(array_column($socket->frames, 'payload'), 'key'), static fn (string $name): bool => str_contains($name, 'model')))->toBe([]);

    // A release has nothing to revoke.
    $release = $runs->release($first);
    expect($release['intent'])->not->toHaveKey('model')->and($release['execution_secrets'])->toBe([]);
});

it('refuses a task start while the ai-router has no guard', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld(dispatch: false);
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));

    expect(fn () => app(TaskVmSlotRuns::class)->claim($slot, 'task-1', str_repeat('a', 40)))->toThrow(ResourceOperationException::class, 'ai-router');
});

it('rebuilds the base from stock only when asked: the run deletes the base first, keeps no recorded snapshot, and cleans the host after', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld(dispatch: false);
    $services = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:services', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'services-public-key']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    slotPoolSocket();
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));
    $commit = str_repeat('d', 40);
    $home = sys_get_temp_dir().'/orbit-refresh-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    File::ensureDirectoryExists($home.'/releases/'.substr($commit, 0, 12).'/apps/gateway');
    file_put_contents($home.'/releases/'.substr($commit, 0, 12).'/REVISION', $commit."\n");
    // The converge reads the machine recipe of the release.
    symlink(base_path('resources'), $home.'/releases/'.substr($commit, 0, 12).'/apps/gateway/resources');
    symlink('releases/'.substr($commit, 0, 12), $home.'/orbit');
    config(['orbit.home' => $home, 'orbit.gateway_checkout' => $home.'/orbit/apps/gateway', 'swamp.proof_club_api_key' => 'club']);
    try {
        $refresh = app(TaskVmImageRefreshIntent::class)->input($host)['intent'];
        $rebuild = app(TaskVmImageRefreshIntent::class)->input($host, fromStock: true)['intent'];
    } finally {
        File::deleteDirectory($home);
    }

    expect($refresh['host'])->toMatchArray(['rebuild' => false, 'current' => 'r20261107120000', 'image' => 'ubuntu-26.04-vm'])
        ->and($rebuild['host'])->toMatchArray(['rebuild' => true, 'current' => null])->and($rebuild['with_pi'])->toBeTrue()
        ->and($refresh['host']['clean'])->toBe(['keep_images' => ['ubuntu-26.04-vm'], 'prefixes' => ['orbit-topology-seed-', 'orbit-baseline-'],
            'retired_projects' => ['orbit-baselines', 'orbit-measure-nr'], 'image_projects' => ['orbit-proto']]);
});

it('withdraws the recorded base for a rebuild, and refuses a rebuild while a refresh is open', function (): void {
    [$host, $project] = slotPoolHost();
    $images = app(TaskVmPromotedImage::class);

    expect($images->latest($host))->not->toBeNull();
    $images->withdraw($host);
    // No start copies a withdrawn base; the pool skips a host without one.
    expect($images->latest($host))->toBeNull()
        ->and(fn () => app(TaskVmSlotPool::class)->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);

    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    expect(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test', fromStock: true))
        ->toThrow(ResourceOperationException::class, 'rebuild after it ends');
});

it('has the removal of a group\'s workspace admit the release of its slot and wait for it, once', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskWorkspaceWaiting::class, 'removes the workspace');
    $releases = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->get();
    expect($releases)->toHaveCount(1)->and($releases->sole()->triggered_by)->toBe('tasks-complete')
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);

    // A second removal waits for that release and admits no other.
    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group->refresh()))->toThrow(TaskWorkspaceWaiting::class)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->count())->toBe(1);
});

it('releases a parked task VM as it is: no start before the removal, and no resume', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $slot->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskWorkspaceWaiting::class, 'removes the workspace')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse()
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('releases a VM of the old pool by its name: its topology seed goes, and the hub peer by the Node\'s public key', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $group = slotPoolGroup($project);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-610', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.140', 'wireguard_ip' => '10.44.0.140', 'user' => 'orbit', 'wireguard_public_key' => str_repeat('o', 43).'=']));
    // As tvm-610: no slot number and no private key on the Gateway.
    $vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Medium, 'name' => 'tvm-610', 'state' => TaskVmState::Failed, 'wireguard_ip' => '10.44.0.140']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskWorkspaceWaiting::class, 'removes the workspace');
    $intent = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $vm->id)->sole()->input['intent'];
    expect($intent['slot'])->toBe(['name' => 'tvm-610', 'seed_clone' => 'tvm-610-topology-seed'])
        ->and($intent['hub_peer']['public_key'])->toBe(str_repeat('o', 43).'=')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Releasing);
});

/** A VM of the old pool, as tvm-610: no slot number and no private key on the Gateway, its Node holding the public key. */
function oldPoolVm(Node $host, ?Task $group = null, string $name = 'tvm-610', TaskVmState $state = TaskVmState::Ready, ?string $publicKey = null): TaskVm
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.140', 'wireguard_ip' => '10.44.0.140', 'user' => 'orbit', 'wireguard_public_key' => $publicKey ?? str_repeat('o', 43).'=']));

    return TaskVm::query()->create(['group_id' => $group?->id, 'host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Medium, 'name' => $name, 'state' => $state, 'wireguard_ip' => '10.44.0.140']);
}

it('has a cancel of an old-pool group carry the release of its VM, so its end deletes the VM and not only its records', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $vm = oldPoolVm($host, $group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Settling])->save();

    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);

    expect($cancel->input['release']['intent']['slot'])->toBe(['name' => 'tvm-610', 'seed_clone' => 'tvm-610-topology-seed'])
        ->and($cancel->input['release']['intent']['host']['node_id'])->toBe($host->id)
        ->and(app(TaskCancelIntent::class)->input($cancel)['intent']['release'])->toBe($cancel->input['release']);
});

it('has the sweep release an old-pool VM again after its release failed', function (): void {
    [$host, $project] = slotPoolHost();
    $vm = oldPoolVm($host, slotPoolGroup($project), state: TaskVmState::Releasing);
    slotPoolSweepWorld();

    // The failed release frees the VM from its group, so only the sweep can release it again.
    app(TaskVmSlotReleaseProjection::class)->record($vm, false, 'task_vm.slot_release_failed');
    expect($vm->refresh()->group_id)->toBeNull()->and($vm->state)->toBe(TaskVmState::Failed);

    app()->call([new SweepTaskVmHosts, 'handle']);

    $release = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('triggered_by', 'task-vm-sweep')->sole();
    expect($release->target_id)->toBe($vm->id)->and($release->input['intent']['slot']['seed_clone'])->toBe('tvm-610-topology-seed')
        ->and($vm->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('releases only a task VM name: any other name of a slotless row refuses, whatever base or seed it names', function (string $name): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    // The host was renamed after a refresh recorded beast-base; a release never takes the shape of a name on trust.
    $host->update(['name' => 'sabre']);
    $vm = oldPoolVm($host->refresh(), name: $name, state: TaskVmState::Failed);

    expect(fn () => app(TaskVmSlotRuns::class)->startRelease($vm, 'test'))->toThrow(ResourceOperationException::class, 'has no task VM name')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()
        ->and($vm->refresh()->state)->toBe(TaskVmState::Failed);
})->with(['beast-base', 'beast-base-seed', 'sabre-base', 'sabre-base-seed', 'tvm-610-seed', 'shark-tvm-3', 'research-vm']);

it('releases tvm-<id> of the old pool and <host>-tvm-<slot> of a slot, under the host name it had, each with its own seed clone', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $old = oldPoolVm($host, name: 'tvm-610', state: TaskVmState::Failed);
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $slot->update(['name' => 'shark-tvm-'.$slot->slot_number, 'group_id' => null, 'state' => TaskVmState::Failed]);

    $runs = app(TaskVmSlotRuns::class);
    expect($runs->startRelease($old, 'test')->input['intent']['slot'])->toBe(['name' => 'tvm-610', 'seed_clone' => 'tvm-610-topology-seed'])
        ->and($runs->startRelease($slot->refresh(), 'test')->input['intent']['slot'])
        ->toBe(['name' => 'shark-tvm-'.$slot->slot_number, 'seed_clone' => 'shark-tvm-'.$slot->slot_number.'-seed']);
});

it('never releases a VM whose host is no task VM host', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    $elsewhere = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'sabre', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.12', 'wireguard_ip' => '10.44.0.12', 'user' => 'orbit']));
    $vm = oldPoolVm($elsewhere, state: TaskVmState::Failed);

    expect(fn () => app(TaskVmSlotRuns::class)->startRelease($vm, 'test'))->toThrow(TaskVmException::class, 'is not a task VM host')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();
});

it('refuses the release of a VM whose Node has no WireGuard public key, which names its hub peer', function (): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    $vm = oldPoolVm($host, state: TaskVmState::Failed, publicKey: '');

    expect(fn () => app(TaskVmSlotRuns::class)->startRelease($vm, 'test'))->toThrow(ResourceOperationException::class, 'has no WireGuard public key')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();
});

it('has the sweep leave a VM alone that a refresh started after the sweep selected it', function (): void {
    [$host] = slotPoolHost();
    slotPoolSweepWorld();
    $vm = oldPoolVm($host, state: TaskVmState::Provisioning);
    // The refresh commits between the sweep's first look at the host and its release: right after the sweep reads its candidates.
    $started = false;
    TaskVm::retrieved(function () use (&$started, $host): void {
        if (! $started) {
            $started = true;
            Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
                'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
        }
    });

    app()->call([new SweepTaskVmHosts, 'handle']);

    expect($started)->toBeTrue()->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()
        ->and($vm->refresh()->state)->toBe(TaskVmState::Provisioning);
});

/**
 * A group whose workspace sits on its parked task VM, with subtask approvals of `$approved` in order, and a GitHub where `$origin['branch']`
 * lists the commits the group's task branch reaches, its head first; without that key the branch does not exist, and `unreadable` fails
 * the read. Any other key is a commit and the commits it reaches. The test may change `$origin` later. The group's pull request has the head
 * `$merged` and the state `$state`, when given. `$reads` records each read with the token it used.
 *
 * @param  list<string>  $approved
 * @param  array<string,list<string>|string>  $origin
 * @param  list<string>  $reads
 * @return array{0:Task,1:TaskVm,2:Node}
 */
function preservationWorld(array $approved, array &$origin, ?string $merged = null, TaskGroupStatus $status = TaskGroupStatus::Settling,
    GitHubPullRequestState $state = GitHubPullRequestState::Merged, array &$reads = []): array
{
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'hardimpactdev', 'organization', 'https://github.com/apps/read', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use (&$origin, &$reads, $merged, $state): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        // Each token names the permission it carries, so a read with the wrong token shows in `$reads`.
        $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('pull-requests-read');
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-read');
        $github->shouldReceive('branchHead')->andReturnUsing(function (string $token, GitHubRepository $repository, string $branch) use (&$origin, &$reads): ?string {
            $reads[] = "branch:{$token}";
            $listed = $origin['branch'] ?? null;

            return $listed === 'unreadable' ? throw GitHubApiException::unavailable() : (is_array($listed) ? $listed[0] : null);
        });
        $github->shouldReceive('compareCommits')->andReturnUsing(function (string $token, GitHubRepository $repository, string $base, string $head) use (&$origin, &$reads): GitHubCommitComparison {
            $reads[] = "compare:{$token}";
            $reached = collect($origin)->first(static fn ($listed): bool => is_array($listed) && ($listed[0] ?? null) === $head) ?? throw GitHubApiException::unavailable();

            return match (true) {
                $reached[0] === $base => new GitHubCommitComparison(GitHubComparisonStatus::Identical, $base, $base),
                in_array($base, $reached, true) => new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $base, $base),
                default => new GitHubCommitComparison(GitHubComparisonStatus::Diverged, $base, str_repeat('0', 40)),
            };
        });
        $github->shouldReceive('pullRequest')->andReturnUsing(function (string $token) use (&$reads, $merged, $state): GitHubPullRequest {
            $reads[] = "pull:{$token}";

            return new GitHubPullRequest($state, null, null, $merged, 'main', mergedAt: $state === GitHubPullRequestState::Merged ? '2026-11-08T08:00:00Z' : null);
        });
    }));
    $url = $merged === null ? null : 'https://github.com/hardimpactdev/orbit/pull/77';
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => $status, 'pr_url' => $url, 'watched_pr_url' => $url]);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Step', 'brief' => 'Brief', 'status' => TaskStatus::Completed]);
    foreach ($approved as $sha) {
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'type' => TaskCommentType::Approved, 'body' => 'Approved.',
            'author' => 'reviewer', 'commit_sha' => $sha, 'posted_at' => now()]);
    }
    $slot = app(TaskVmSlotPool::class)->take($group);
    $slot->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->save();

    return [$group, $slot, Node::query()->where('name', 'gateway')->sole()];
}

/** One `task:complete` or `task:cancel` call from the Gateway Node. */
function preservationCall(Node $gateway, Task $group, string $command): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->withHeader('Idempotency-Key', 'end-'.fake()->uuid())
        ->postJson("/api/v1/tasks/{$group->id}/{$command}");
}

it('refuses task:complete while origin lacks the latest approval, though the approved commit before it merged, and changes nothing', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    // A merged and GitHub deleted the branch; B was approved later and lives only in the parked VM.
    $origin = [$a => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a, $b], $origin, merged: $a, status: TaskGroupStatus::Running);

    preservationCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished')
        ->assertJsonPath('error.details.commit', $b)->assertJsonPath('error.details.branch', 'task-'.$group->id);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->watched_pr_completion)->toBeNull()->and($group->taskable_id)->not->toBeNull()
        ->and($slot->refresh()->state)->toBe(TaskVmState::Parked)
        ->and(Operation::query()->whereIn('kind', [TaskVmSlotPool::ReleaseKind, 'task_vm.resume'])->exists())->toBeFalse();

    // task:publish pushed B; now the group completes and its parked VM goes as it is.
    $origin['branch'] = [$b, $a];
    preservationCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($slot->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->count())->toBe(1)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse();
});

it('takes the merged pull request\'s head for a deleted task branch, so a merged latest approval completes', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running);

    preservationCall($gateway, $group, 'complete')->assertOk();

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('refuses task:cancel while origin lacks the latest approval, admitting nothing, and admits a release with no push once it has it', function (): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a, $b], $origin);

    preservationCall($gateway, $group, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished')
        ->assertJsonPath('error.details.commit', $b);
    expect(Operation::query()->exists())->toBeFalse()->and($slot->refresh()->state)->toBe(TaskVmState::Parked);

    $origin['branch'] = [$b, $a];
    preservationCall($gateway, $group, 'cancel')->assertStatus(202);

    $cancel = Operation::query()->where('kind', 'tasks.cancel')->sole();
    expect($cancel->input)->not->toHaveKey('push')->and($cancel->input['release']['intent']['slot']['name'])->toBe($slot->name)
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse();
});

it('refuses the release of a resuming task VM, completes the group with the reason, and releases it once on retries after the resume', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);
    $resume = Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    preservationCall($gateway, $group, 'complete')->assertOk();
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Completed)->and($group->assistance_reason)->toContain('task_vm.resume')
        ->and(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse()->and($slot->refresh()->state)->toBe(TaskVmState::Parked);

    $resume->update(['status' => OperationStatus::Succeeded]);
    preservationCall($gateway, $group, 'complete')->assertOk();
    preservationCall($gateway, $group, 'complete')->assertOk();

    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('target_id', $slot->id)->count())->toBe(1)
        ->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('reads the task branch and compares with a contents:read token, and the pull request with a pull_requests:read token', function (): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => [$a]];
    $reads = [];
    [$group, , $gateway] = preservationWorld([$a], $origin, reads: $reads);
    preservationCall($gateway, $group, 'complete')->assertOk();
    expect($reads)->toBe(['branch:contents-read', 'compare:contents-read']);
});

it('reads a merged pull request with its own token when its branch is gone, and compares its head with the contents token', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    $reads = [];
    [$merged, , $gateway] = preservationWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, reads: $reads);
    preservationCall($gateway, $merged, 'complete')->assertOk();
    expect($reads)->toContain('branch:contents-read', 'pull:pull-requests-read', 'compare:contents-read')
        ->and($reads)->not->toContain('compare:pull-requests-read', 'branch:pull-requests-read');
});

it('refuses when the task branch cannot be read, though the merged pull request\'s head is the approved commit', function (): void {
    $a = str_repeat('a', 40);
    $origin = ['branch' => 'unreadable', $a => [$a]];
    $reads = [];
    [$group, $slot, $gateway] = preservationWorld([$a], $origin, merged: $a, status: TaskGroupStatus::Running, reads: $reads);

    preservationCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');
    // The completion reads its watched pull request first; the failed branch read ends the check before any compare.
    expect($reads)->toBe(['pull:pull-requests-read', 'branch:contents-read'])->and($group->refresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($slot->refresh()->state)->toBe(TaskVmState::Parked);
});

it('refuses when the task branch is gone and its pull request closed without a merge, though the head is the approved commit', function (): void {
    $a = str_repeat('a', 40);
    $origin = [$a => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a], $origin, merged: $a, state: GitHubPullRequestState::Closed);

    preservationCall($gateway, $group, 'complete')->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Settling)->and($slot->refresh()->state)->toBe(TaskVmState::Parked);
});

it('leaves the group\'s open operations untouched when a complete or a cancel refuses', function (string $command): void {
    [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
    $origin = ['branch' => [$a]];
    [$group, $slot, $gateway] = preservationWorld([$a, $b], $origin);
    $turn = Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    preservationCall($gateway, $group, $command)->assertStatus(409)->assertJsonPath('error.code', 'tasks.approved_work_unpublished');

    expect($turn->refresh()->status)->toBe(OperationStatus::Running)->and($turn->cancel_requested_at)->toBeNull()
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Settling)->and($slot->refresh()->state)->toBe(TaskVmState::Parked);
})->with(['complete', 'cancel']);

it('refuses a cancel while a resume of the group\'s VM is open, cancelling nothing, and admits it after the resume', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);
    $resume = Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);
    $turn = Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    preservationCall($gateway, $group, 'cancel')->assertStatus(409)->assertJsonPath('error.code', 'operation.target_busy')
        ->assertJsonPath('error.details.operation_id', $resume->id);
    expect($turn->refresh()->cancel_requested_at)->toBeNull()->and(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse()
        ->and($slot->refresh()->state)->toBe(TaskVmState::Parked);

    $resume->update(['status' => OperationStatus::Succeeded]);
    $turn->update(['status' => OperationStatus::Succeeded]);
    preservationCall($gateway, $group, 'cancel')->assertStatus(202);
    expect($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('reserves the VM at cancel admission, so a resume asked for later starts nothing', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);

    preservationCall($gateway, $group, 'cancel')->assertStatus(202);

    expect($slot->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and(app(StartTaskVmResumeAction::class)->execute($slot->id))->toBeNull()
        ->and(app(WakeTaskVmAction::class)->forGroup($group->refresh()))->toBeTrue()
        ->and(Operation::query()->where('kind', 'task_vm.resume')->exists())->toBeFalse();
});

it('frees the VM a cancel reserved when its start is refused, so the next cancel reserves it again and runs', function (): void {
    $origin = [];
    [$group, $slot, $gateway] = preservationWorld([], $origin);
    preservationCall($gateway, $group, 'cancel')->assertStatus(202);
    $refused = Operation::query()->where('kind', 'tasks.cancel')->sole();
    expect($slot->refresh()->state)->toBe(TaskVmState::Releasing);

    // Swamp refused the start: the Operation fails before any run exists.
    $refused->update(['status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused', 'lock_key' => null, 'finished_at' => now()]);
    app(TaskCancelProjection::class)->refused($refused);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('swamp.start_refused')
        ->and($slot->group_id)->toBe($group->id)->and($group->refresh()->status)->toBe(TaskGroupStatus::Settling);

    preservationCall($gateway, $group, 'cancel')->assertStatus(202);
    $retry = Operation::query()->where('kind', 'tasks.cancel')->whereKeyNot($refused->id)->sole();
    expect($retry->input['release']['intent']['slot']['name'])->toBe($slot->name)->and($slot->refresh()->state)->toBe(TaskVmState::Releasing);
});

it('ends a removal failure assistance when the release deletes the group\'s workspace, and leaves other requests alone', function (): void {
    [, $project] = slotPoolHost();
    $pool = app(TaskVmSlotPool::class);
    $workspace = function (Task $group, TaskVm $slot, string $name) use ($project): Instance {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => $name, 'checkout_path' => '/home/orbit/apps/orbit/'.$name,
            'task_workspace_owner_id' => $group->id, 'branch_override' => $name]);
        $group->taskable()->associate($instance);
        $group->fill(['status' => TaskGroupStatus::Completed])->save();

        return $instance;
    };
    // The removal failed while the VM was parked; the release now deletes the workspace.
    $failed = slotPoolGroup($project);
    $failedSlot = $pool->take($failed);
    $workspace($failed, $failedSlot, 'task-1');
    TaskAssistance::apply($failed, AssistanceKind::RemovalFailure, null, 'Workspace removal failed: the task VM is parked.', replaceFailure: true);
    // A running group asks about something else; its VM is released too, as after a failed start.
    $other = slotPoolGroup($project);
    $otherSlot = $pool->take($other);
    $workspace($other, $otherSlot, 'task-2');
    $other->update(['status' => TaskGroupStatus::Running]);
    TaskAssistance::apply($other, AssistanceKind::Failure, null, 'The check failed.', replaceFailure: true);

    $projection = app(TaskVmSlotReleaseProjection::class);
    foreach ([$failedSlot, $otherSlot] as $slot) {
        $projection->record($slot->refresh(), true, null);
    }

    expect($failed->refresh()->assistance_requested)->toBeFalse()->and($failed->assistance_kind)->toBeNull()->and($failed->taskable_id)->toBeNull()
        ->and($other->refresh()->assistance_kind)->toBe(AssistanceKind::Failure)->and($other->assistance_requested)->toBeTrue();
});

it('never lets task work take the reserved VM place', function (): void {
    [, $project] = slotPoolHost(maxVms: 9);
    $pool = app(TaskVmSlotPool::class);
    $pool->take(slotPoolGroup($project));
    $pool->take(slotPoolGroup($project));

    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
    expect(TaskVm::query()->live()->pluck('slot_number')->all())->toBe([2, 3]);
});

it('lets CI use the reserve while task work fills the task places', function (): void {
    [, $project] = slotPoolHost(maxVms: 9);
    $pool = app(TaskVmSlotPool::class);
    $pool->take(slotPoolGroup($project));
    $pool->take(slotPoolGroup($project));

    $ci = $pool->take(slotPoolGroup($project), proof: true);

    expect($ci->slot_number)->toBe(1);
    expect(fn () => $pool->take(slotPoolGroup($project), proof: true))->toThrow(TaskCapacityException::class);
});

it('lets a refresh use the shared reserve while task work fills the task places', function (): void {
    [$host, $project] = slotPoolHost(maxVms: 9);
    $pool = app(TaskVmSlotPool::class);
    $pool->take(slotPoolGroup($project));
    $pool->take(slotPoolGroup($project));

    $refresh = $pool->take(null, $host->id);

    expect($refresh->slot_number)->toBe(1)->and($refresh->group_id)->toBeNull();
});

it('counts an occupied reserve once and keeps the full task budget available', function (): void {
    [, $project] = slotPoolHost(maxVms: 9);
    $pool = app(TaskVmSlotPool::class);
    $ci = $pool->take(slotPoolGroup($project), proof: true);

    expect($ci->slot_number)->toBe(1);
    expect($pool->take(slotPoolGroup($project))->slot_number)->toBe(2)
        ->and($pool->take(slotPoolGroup($project))->slot_number)->toBe(3);
    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
});
