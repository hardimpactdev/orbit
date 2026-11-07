<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmReadyCopiesAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmReadyCopiesProjection;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

/** A ready task VM with its host and active guest Node, and a vault that records its puts. */
function readyCopiesWorld(TaskVmState $state = TaskVmState::Ready): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-554', '10.44.0.131');
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-554',
        'state' => $state, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.131', 'size' => TaskVmSize::Normal]);
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
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token', 'swamp.proof_club_api_key' => 'proof-club-key']);

    return [$vm, $host, $guest, $socket];
}

function readyCopiesRefusal(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $error) {
        return $error->errorCode;
    }

    throw new RuntimeException('The call was not refused.');
}

/** @return array<string,mixed> */
function readyCopiesRun(string $status, string $endCheck = 'succeeded'): array
{
    return ['run_id' => 'fixture-ready-copies', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Check the ready copies' => ['status' => $endCheck]], 'summary' => ['failed_steps' => $endCheck === 'failed' ? ['Check the ready copies'] : []]]];
}

it('renders the full public intent of the save and sends only the vault name of the club key', function (): void {
    [$vm, $host, $guest, $socket] = readyCopiesWorld();

    $input = app(TaskVmReadyCopiesIntent::class)->capture($vm);

    expect($input)->toMatchArray(['task_vm_id' => $vm->id, 'node_id' => $host->id, 'guest_node_id' => $guest->id]);
    expect($input['fleet'])->toBe(['guests' => ['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'], 'network' => 'oe-nested', 'format' => '4']);
    expect($input['ssh_execution']['target'])->toMatchArray(['host' => '10.44.0.131', 'user' => 'orbit', 'port' => 22]);
    expect($input['execution_secrets'])->toBe(['proof_club' => ['vault' => 'orbit-control-inputs', 'key' => 'swamp-proof-club-api-key']]);
    expect(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain('proof-club-key');
    expect(collect($socket->frames)->pluck('payload.key')->all())->toBe(['swamp-proof-club-api-key']);

    $operation = Operation::query()->create(['kind' => 'task_vm.ready_copies', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $host->id,
        'status' => 'queued', 'sequence' => 1, 'attempts' => 0, 'input' => $input]);
    $envelope = app(TaskVmReadyCopiesProjection::class)->input($operation);
    expect(array_keys($envelope['execution_secrets']))->toBe(['proof_club']);
    expect($envelope['intent'])->not->toHaveKey('execution_secrets')->toHaveKeys(['ssh_execution', 'fleet']);
})->group('ready-copies');

it('refuses a VM that is not ready, and a save without the club key, before any credential is copied', function (): void {
    [$vm, , , $socket] = readyCopiesWorld(TaskVmState::Parked);

    expect(readyCopiesRefusal(fn () => app(TaskVmReadyCopiesIntent::class)->capture($vm)))->toBe('task_vm.ready_copies_refused');

    $vm->update(['state' => TaskVmState::Ready]);
    config(['swamp.proof_club_api_key' => '']);
    expect(readyCopiesRefusal(fn () => app(TaskVmReadyCopiesIntent::class)->capture($vm->refresh())))->toBe('task_vm.ready_copies_unavailable');
    expect($socket->frames)->toBe([]);
})->group('ready-copies');

it('admits one save as one Operation on the VM and returns the open one for a second', function (): void {
    [$vm, $host] = readyCopiesWorld();
    $admit = static function () use ($vm): Operation {
        try {
            return app(StartTaskVmReadyCopiesAction::class)->execute($vm->id);
        } catch (Throwable $error) {
            // The run start after the commit needs a Swamp server the suite has not; the Operation is recorded before it.
            $operation = Operation::query()->where('kind', 'task_vm.ready_copies')->latest('id')->first();
            if (! $operation instanceof Operation || ! str_contains($error::class, 'Swamp') && ! $error instanceof ResourceOperationException) {
                throw $error;
            }

            return $operation;
        }
    };

    $operation = $admit();

    expect([$operation->kind, $operation->target_type, $operation->target_id, $operation->node_id])->toBe(['task_vm.ready_copies', 'task_vm', $vm->id, $host->id]);
    expect($admit()->id)->toBe($operation->id);
    expect(Operation::query()->where('kind', 'task_vm.ready_copies')->count())->toBe(1);
})->group('ready-copies');

it('records a succeeded save only with its passed end check', function (): void {
    [$vm, $host] = readyCopiesWorld();
    $input = app(TaskVmReadyCopiesIntent::class)->capture($vm);
    $operation = Operation::query()->create(['kind' => 'task_vm.ready_copies', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $host->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => $input, 'state' => ['swamp_run_id' => 'fixture-ready-copies']]);

    expect(readyCopiesRefusal(fn () => app(TaskVmReadyCopiesProjection::class)->apply($operation, readyCopiesRun('succeeded', 'failed'))))->toBe('operation.result_invalid');

    $done = app(TaskVmReadyCopiesProjection::class)->apply($operation->refresh(), readyCopiesRun('succeeded'));

    expect($done->status->value)->toBe('succeeded');
})->group('ready-copies');

it('refuses to project a save whose VM is no longer the admitted one', function (): void {
    [$vm, $host] = readyCopiesWorld();
    $input = app(TaskVmReadyCopiesIntent::class)->capture($vm);
    $operation = Operation::query()->create(['kind' => 'task_vm.ready_copies', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $host->id + 1,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => $input]);

    expect(readyCopiesRefusal(fn () => app(TaskVmReadyCopiesProjection::class)->input($operation)))->toBe('operation.execution_forbidden');
})->group('ready-copies');

it('registers the operator command that admits the save', function (): void {
    expect(array_keys(Artisan::all()))->toContain('task-vms:save-ready-copies');
})->group('ready-copies');
