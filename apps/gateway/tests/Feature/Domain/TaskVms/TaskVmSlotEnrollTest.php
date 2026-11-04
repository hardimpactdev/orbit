<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmSlotEnrollAction;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSlotEnrollIntent;
use App\Domain\TaskVms\TaskVmSlotEnrollProjection;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

/** A configured task VM host with its dev Cluster, and a model router that is ready. */
function slotFixture(): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit',
    ]));
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    // Recording a host key asks Swamp to publish the pins; this suite starts no run.
    app()->instance(WorkerKnownHostsPublisher::class, new class implements WorkerKnownHostsPublisher
    {
        public function publish(): void {}
    });
    app()->instance(ModelKeyIssuer::class, new class implements ModelKeyIssuer
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8317';
        }

        public function ownerNodeId(): int
        {
            return 3;
        }

        public function privateMaterial(string $origin, int $ownerNodeId): array
        {
            return ['management_key' => str_repeat('1', 64), 'anchor_key' => str_repeat('2', 64)];
        }
    });

    return [$cluster, $host];
}

/** A slot that is enrolling, with the Node and Process the Gateway records at admission, and the Operation that enrols it. */
function enrollingSlot(): array
{
    [, $host] = slotFixture();
    $vm = app(TaskVmSlotEnrollIntent::class)->reserve($host, 1);
    $input = ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'slot' => 1, 'name' => $vm->name, 'identity' => ['revision' => str_repeat('b', 64)]];
    $operation = Operation::query()->create(['kind' => 'task_vm.slot_enroll', 'target_type' => 'task_vm', 'target_id' => $vm->id,
        'node_id' => $host->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => $input, 'state' => ['swamp_run_id' => 'fixture-slot']]);

    return [$host, $vm, $operation];
}

/** @return array<string,mixed> */
function slotRun(string $status, ?string $report = null, string $endCheck = 'succeeded'): array
{
    return ['run_id' => 'fixture-slot', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Check the slot' => ['status' => $endCheck]], 'summary' => ['failed_steps' => $endCheck === 'failed' ? ['Check the slot'] : []],
            ...($report === null ? [] : ['slot' => ['stdout' => $report."\n"]])]];
}

function slotReport(array $change = []): string
{
    return json_encode([
        'wireguard_public_key' => str_repeat('A', 43).'=', 'observer_digest' => str_repeat('c', 64),
        'ssh_host_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEQ9Q5+auc2KFs6ebCBB3J9ROM+aIRnhyJwafKXHSCWT', ...$change,
    ], JSON_THROW_ON_ERROR);
}

it('records a slot with its address, Node, role, Pi Process and secrets, and keeps them on a rerun', function (): void {
    Http::preventStrayRequests();
    [, $host] = slotFixture();

    $vm = app(TaskVmSlotEnrollIntent::class)->reserve($host, 2);

    expect($vm->name)->toBe('beast-tvm-2');
    expect($vm->state)->toBe(TaskVmState::Provisioning);
    expect([$vm->slot, $vm->group_id, $vm->host_node_id, $vm->provider])->toBe([2, null, $host->id, TaskVmProviderKind::Incus]);
    expect([$vm->bridge_address, $vm->address])->toBe(['10.251.77.253', '10.251.77.253']);
    expect($vm->bridge_mac)->toMatch('/\A10:66:6a(?::[0-9a-f]{2}){3}\z/D');
    expect($vm->wireguard_ip)->toStartWith('10.44.0.');
    expect($vm->pi_token)->toMatch('/\A[a-f0-9]{64}\z/D');
    expect($vm->model_key)->toMatch('/\A[a-f0-9]{64}\z/D');
    expect($vm->node->name)->toBe('beast-tvm-2');
    expect($vm->node->roles()->where('role', RoleName::AppDev)->exists())->toBeTrue();
    expect(Process::query()->where('owner_type', Node::class)->where('owner_id', $vm->node_id)->where('name', 'pi-server')->count())->toBe(1);

    $again = app(TaskVmSlotEnrollIntent::class)->reserve($host, 2);

    expect($again->id)->toBe($vm->id);
    expect([$again->pi_token, $again->model_key, $again->wireguard_ip, $again->node_id])->toBe([$vm->pi_token, $vm->model_key, $vm->wireguard_ip, $vm->node_id]);
    expect(TaskVm::query()->count())->toBe(1);
    Http::assertNothingSent();
})->group('slots');

it('refuses an enrolled slot, an unconfigured host and a slot beyond the limit', function (): void {
    [, $host] = slotFixture();
    $vm = app(TaskVmSlotEnrollIntent::class)->reserve($host, 1);
    $vm->update(['state' => TaskVmState::Free]);
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'plain', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']));

    $code = static fn (Closure $call): string => rescue(fn () => $call() ?? 'none', static fn (ResourceOperationException $error): string => $error->errorCode, false);

    expect($code(fn () => app(TaskVmSlotEnrollIntent::class)->reserve($host, 1)))->toBe('task_vm.slot_exists');
    expect($code(fn () => app(TaskVmSlotEnrollIntent::class)->reserve($other, 1)))->toBe('task_vm.host_unavailable');
    expect($code(fn () => app(TaskVmSlotEnrollIntent::class)->reserve($host, 65)))->toBe('task_vm.slot_invalid');
})->group('slots');

it('enrols a failed slot again and gives it back its state', function (): void {
    [, $host] = slotFixture();
    $vm = app(TaskVmSlotEnrollIntent::class)->reserve($host, 1);
    $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'operation.native_failed', 'error_message' => 'boom']);

    $again = app(TaskVmSlotEnrollIntent::class)->reserve($host, 1);

    expect($again->id)->toBe($vm->id);
    expect([$again->state, $again->error_code])->toBe([TaskVmState::Provisioning, null]);
})->group('slots');

it('counts no slot against the capacity of the host', function (): void {
    [, $host] = slotFixture();
    $before = app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts());
    app(TaskVmSlotEnrollIntent::class)->reserve($host, 1)->update(['state' => TaskVmState::Free]);
    app(TaskVmSlotEnrollIntent::class)->reserve($host, 2);

    expect(app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts()))->toBe($before);
})->group('slots');

it('makes the slot free and its Node active from the report of the proof VM', function (): void {
    Http::preventStrayRequests();
    [, $vm, $operation] = enrollingSlot();

    app(TaskVmSlotEnrollProjection::class)->apply($operation, slotRun('succeeded', slotReport()));

    $vm->refresh();
    $node = $vm->node->refresh();
    expect($vm->state)->toBe(TaskVmState::Free);
    expect([$vm->identity_revision, $vm->group_id, $vm->ready_at, $vm->error_code])->toBe([str_repeat('b', 64), null, null, null]);
    expect($node->status)->toBe(LifecycleStatus::Active);
    expect([$node->wireguard_public_key, $node->agent_secret_hash])->toBe([str_repeat('A', 43).'=', str_repeat('c', 64)]);
    expect([$node->ssh_host_key_type, $node->ssh_host_key])->toBe(['ssh-ed25519', 'AAAAC3NzaC1lZDI1NTE5AAAAIEQ9Q5+auc2KFs6ebCBB3J9ROM+aIRnhyJwafKXHSCWT']);
    expect($node->ssh_host_fingerprint)->toStartWith('SHA256:');
    expect($node->roles()->where('role', RoleName::AppDev)->first()->status)->toBe(LifecycleStatus::Active);
    expect($operation->refresh()->status->value)->toBe('succeeded');
    expect($operation->result)->toMatchArray(['ready' => true, 'name' => 'beast-tvm-1']);
    Http::assertNothingSent();
})->group('slots');

it('marks the slot failed, keeps its Node and takes no key from a failed run', function (): void {
    [, $vm, $operation] = enrollingSlot();

    app(TaskVmSlotEnrollProjection::class)->apply($operation, [...slotRun('failed', null, 'failed'), 'error_code' => 'operation.native_failed']);

    $vm->refresh();
    expect($vm->state)->toBe(TaskVmState::Failed);
    expect($vm->error_code)->toBe('operation.native_failed');
    expect($vm->node->refresh()->wireguard_public_key)->toBeNull();
    expect($vm->node->status)->toBe(LifecycleStatus::Provisioning);
    expect($operation->refresh()->status->value)->toBe('failed');
})->group('slots');

it('refuses a success without the end check or with a bad report', function (string $case): void {
    [, $vm, $operation] = enrollingSlot();
    $run = match ($case) {
        'end check failed' => slotRun('succeeded', slotReport(), 'failed'),
        'no report' => slotRun('succeeded'),
        'bad key' => slotRun('succeeded', slotReport(['wireguard_public_key' => 'short'])),
        'bad digest' => slotRun('succeeded', slotReport(['observer_digest' => 'x'])),
        'not ed25519' => slotRun('succeeded', slotReport(['ssh_host_key' => 'ssh-rsa AAAA'])),
    };

    expect(fn () => app(TaskVmSlotEnrollProjection::class)->apply($operation, $run))->toThrow(ResourceOperationException::class);

    expect($vm->refresh()->state)->toBe(TaskVmState::Provisioning);
    expect($operation->refresh()->status->value)->toBe('running');
})->with(['end check failed', 'no report', 'bad key', 'bad digest', 'not ed25519'])->group('slots');

it('reports nothing for a free slot, which has no machine and an offline observer', function (): void {
    [, $vm] = enrollingSlot();
    $vm->node->forceFill(['status' => LifecycleStatus::Active])->save();
    $report = static fn (): string => json_encode(app(FactDoctor::class)->report([$vm->node->refresh()], [])->toArray(), JSON_THROW_ON_ERROR);

    $vm->update(['state' => TaskVmState::Parked]);
    $parked = $report();
    $vm->update(['state' => TaskVmState::Free]);
    $free = $report();

    // A parked VM raises its notice; the same Node as a free slot raises nothing, not even that notice.
    expect($parked)->toContain('node.task_vm_parked');
    expect($free)->not->toContain('node.task_vm_parked')->not->toContain('"code"');
})->group('slots');

/** A Swamp serve that takes every vault.put, so the copies the Gateway pushes can be read back. */
function fakeVaultSocket(): object
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
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/** The hub, the Gateway Node, the hub key file and a promoted image that an enrolment reads. Returns [$host, $hub, $gateway]. */
function enrollmentWorld(): array
{
    [, $host] = slotFixture();
    $keys = ['ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key'];
    $host->forceFill($keys)->save();
    $hub = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '203.0.113.5', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', ...$keys]));
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit', ...$keys]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    @mkdir(config('orbit.home').'/wireguard', 0o700, true);
    file_put_contents(config('orbit.home').'/wireguard/public.key', str_repeat('K', 43)."=\n");
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => 'c1c2cf4051fb0123']]]);

    return [$host, $hub, $gateway];
}

it('renders the full public intent of an enrolment and sends only vault names for the secrets', function (): void {
    Http::preventStrayRequests();
    $socket = fakeVaultSocket();
    [$host, $hub, $gateway] = enrollmentWorld();
    $vm = app(TaskVmSlotEnrollIntent::class)->reserve($host, 1);

    $input = app(TaskVmSlotEnrollIntent::class)->capture($vm);

    expect($input)->toMatchArray(['task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'slot' => 1, 'name' => 'beast-tvm-1',
        'bridge_address' => '10.251.77.254', 'gateway_node_id' => $gateway->id, 'model_router_node_id' => 3, 'model_origin' => 'http://10.44.0.3:8317']);
    expect($input['identity'])->toMatchArray(['project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'volume' => 'beast-tvm-1-identity', 'model_key_mark' => '@MODEL_KEY@']);
    expect(array_keys($input['identity']['files']))->toBe(['etc/wireguard/orbit.conf', 'etc/wireguard/orbit.dns-link', 'etc/cloud/cloud.cfg.d/99-orbit-slot.cfg', 'home/orbit/.pi/agent/models.json']);
    expect($input['identity']['files']['etc/wireguard/orbit.conf'])->toContain('PostUp = wg set %i private-key /etc/wireguard/orbit.key');
    expect($input['identity']['revision'])->toMatch('/\A[a-f0-9]{64}\z/D');
    expect($input['hub'])->toMatchArray(['hub_id' => $hub->id, 'file' => '/etc/wireguard/orbit-peers.d/'.$vm->node_id.'.conf']);
    expect($input['hub']['section'])->toContain('PublicKey = @KEY@')->toContain('AllowedIPs = '.$vm->wireguard_ip.'/32');
    expect($input['vm'])->toMatchArray(['fingerprint' => 'c1c2cf4051fb0123', 'seed_source' => 'orbit-topology-seed-c1c2cf4051fb', 'seed_clone' => 'beast-tvm-1-topology-seed']);
    expect(json_decode($input['vm']['config_json'], true, flags: JSON_THROW_ON_ERROR)['devices']['identity']['readonly'])->toBe('true');
    expect($input['guest'])->toMatchArray(['user' => 'orbit', 'address' => $vm->wireguard_ip, 'pi_sha256' => str_repeat('a', 64)]);
    expect($input['guest']['agent_sha256'])->toMatch('/\A[a-f0-9]{64}\z/D');

    expect($input['execution_secrets'])->toBe([
        'model_key' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-model-key-'.$vm->id],
        'model_router_management' => ['vault' => 'orbit-control-inputs', 'key' => 'model-router-management-node-3'],
        'model_router_anchor' => ['vault' => 'orbit-control-inputs', 'key' => 'model-router-anchor-node-3'],
        'pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id],
    ]);
    $encoded = json_encode($input, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($vm->pi_token)->not->toContain($vm->model_key)->not->toContain(str_repeat('1', 64));
    expect(collect($socket->frames)->pluck('payload.key')->sort()->values()->all())->toBe(['model-router-anchor-node-3', 'model-router-management-node-3',
        'task-vm-model-key-'.$vm->id, 'task-vm-pi-token-'.$vm->id]);

    $operation = Operation::query()->create(['kind' => 'task_vm.slot_enroll', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $host->id,
        'status' => 'queued', 'sequence' => 1, 'attempts' => 0, 'input' => $input]);
    $envelope = app(TaskVmSlotEnrollProjection::class)->input($operation);
    expect(array_keys($envelope['execution_secrets']))->toBe(['model_key', 'model_router_management', 'model_router_anchor', 'pi_token']);
    expect($envelope['intent'])->not->toHaveKey('execution_secrets')->toHaveKey('identity');
    Http::assertNothingSent();
})->group('slots');

it('admits one slot enrolment as one Operation on the host and refuses a second while it runs', function (): void {
    Http::preventStrayRequests();
    fakeVaultSocket();
    [$host] = enrollmentWorld();

    $operation = rescue(fn (): Operation => app(StartTaskVmSlotEnrollAction::class)->execute($host, 1), fn (Throwable $error): Operation => Operation::query()->latest('id')->firstOrFail(), false);

    $vm = TaskVm::query()->where('slot', 1)->sole();
    expect(Operation::query()->where('kind', 'task_vm.slot_enroll')->count())->toBe(1);
    expect([$operation->kind, $operation->target_type, $operation->target_id, $operation->node_id])->toBe(['task_vm.slot_enroll', 'task_vm', $vm->id, $host->id]);
    expect($operation->input['identity']['volume'])->toBe('beast-tvm-1-identity');
    expect($operation->lock_key)->toBe('task_vm:'.$vm->id);

    $again = rescue(fn (): Operation => app(StartTaskVmSlotEnrollAction::class)->execute($host, 1), fn (Throwable $error): Operation => Operation::query()->latest('id')->firstOrFail(), false);

    expect($again->id)->toBe($operation->id);
    expect(Operation::query()->where('kind', 'task_vm.slot_enroll')->count())->toBe(1);
})->group('slots');
