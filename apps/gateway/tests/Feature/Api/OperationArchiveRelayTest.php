<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationDispatchNotStarted;
use App\Domain\Operations\OperationFamilyArchiveRelay;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\OperationArchiveRelayTransport;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

final class RelayFamily implements OperationCompletion, OperationFamilyArchiveRelay, OperationFamilyChildren, OperationFamilyExecution
{
    public int $limit = 10000;

    public string $archive = '/tmp/orbit-transfer-fixture.tar';

    public bool $resumable = false;

    public string $reader = 'instance.read-transfer-archive';

    public ?array $readInput = null;

    public function childContext(Operation $operation): array
    {
        return ['intent' => ['nodes' => $operation->input['nodes'] ?? []], 'children' => $operation->state['child_intents'] ?? []];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => array_map(fn (array $descriptor): array => [...$descriptor, 'resumable' => $this->resumable], $operation->input['descriptors'] ?? [])];
    }

    public function input(Operation $operation, array $run): array
    {
        return $operation->input['payload'];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}

    public function archiveRelay(Operation $operation, array $run): array
    {
        return ['source_transport' => 'agent', 'source_name' => 'instance.transfer-source',
            'read' => ['name' => $this->reader, 'input' => $this->readInput ?? ['archive' => $this->archive]], 'max_bytes' => $this->limit];
    }
}
final class RelayAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute($intent['kind'], 'node', $intent['node_id'], $intent['node_id'], $intent['input'], 'test', null);
    }
}

beforeEach(function (): void {
    $node = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'wireguard_ip' => $ip, 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.1']);
    $gateway = $node('gateway', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => 'active']);
    $peer = $node('services', '10.44.0.17');
    $peer->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $peer->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);
    $this->sourceNode = $node('source', '10.44.0.2');
    $this->destinationNode = $node('destination', '10.44.0.3');
    $this->family = new RelayFamily;
    app()->instance(RelayFamily::class, $this->family);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->bytes = "private archive\x00\xff";
    $this->metadata = ['bytes' => strlen($this->bytes), 'sha256' => hash('sha256', $this->bytes)];
    $this->agents->answer('instance.transfer-source', [...$this->metadata, 'archive' => '/tmp/orbit-transfer-fixture.tar',
        'head' => str_repeat('a', 40), 'branch' => 'main', 'detached' => false, 'common' => null, 'refs' => ['main']]);
    $this->agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $this->sink = $output;
        $output->write($this->bytes);

        return $this->metadata;
    });
    $this->agents->answer('instance.materialize-transfer', function (array $input, Node $node, ?AgentBinaryOutput $output, ?AgentBinaryInput $binary): array {
        $chunks = iterator_to_array($binary->envelope("header\n")->chunks());
        expect(stream_get_contents($chunks[1]))->toBe($this->bytes);
        $this->envelope = $input[EffectReceiptMetadata::InputKey]->envelope();

        return $this->metadata;
    });
    $base = ['executor' => 'swamp', 'completion' => RelayFamily::class, 'execution' => RelayFamily::class,
        'result_fields' => ['archive', 'bytes', 'sha256', 'head', 'branch', 'detached', 'common', 'error_code'],
        'completion_contract' => ['step' => 'archive', 'result' => $this->metadata]];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(),
        'test.relay_parent' => [...$base, 'runs' => [], 'completion_contract' => ['children_succeeded' => ['test.relay_source', 'test.relay_destination']], 'children' => [
            ['kind' => 'test.relay_source', 'node_from' => 'intent.nodes'], ['kind' => 'test.relay_destination', 'node_from' => 'intent.nodes']]],
        'test.relay_source' => [...$base, 'admission' => RelayAdmission::class,
            'runs' => [['transport' => 'agent', 'name' => 'instance.transfer-source']],
            'completion_contract' => [...$base['completion_contract'], 'transport' => 'agent', 'name' => 'instance.transfer-source']],
        'test.relay_destination' => [...$base, 'admission' => RelayAdmission::class,
            'runs' => [['transport' => 'archive_relay', 'name' => 'instance.materialize-transfer', 'resumable' => true]],
            'completion_contract' => [...$base['completion_contract'], 'transport' => 'archive_relay', 'name' => 'instance.materialize-transfer']]]));
    $this->parent = app(StartOperationAction::class)->execute('test.relay_parent', 'project', 111, $gateway->id,
        ['nodes' => [$this->sourceNode->id, $this->destinationNode->id]], 'test', null);
    $this->handle = ['parent_operation_id' => $this->parent->id, 'child_key' => 'source', 'run_key' => 'capture'];
    $this->payload = ['relay' => $this->handle, 'input' => [...$this->metadata, 'destination' => '/srv/copy', 'environment' => '/srv/copy/.env',
        'head' => str_repeat('a', 40), 'branch' => 'main', 'detached' => false]];
    $producer = ['operation' => 'capture', 'source' => '/srv/source', 'layout' => 'checkout', 'sqlite_source' => null];
    $descriptor = static fn (string $key, string $transport, string $name, array $input): array => compact('key', 'transport', 'name', 'input') + ['step' => 'archive', 'required' => true, 'resumable' => false];
    $this->parent->update(['state' => ['child_intents' => [
        ['key' => 'source', 'kind' => 'test.relay_source', 'node_id' => $this->sourceNode->id, 'input' => ['payload' => $producer, 'descriptors' => [$descriptor('capture', 'agent', 'instance.transfer-source', $producer)]]],
        ['key' => 'destination', 'kind' => 'test.relay_destination', 'node_id' => $this->destinationNode->id, 'input' => ['payload' => $this->payload, 'descriptors' => [$descriptor('deliver', 'archive_relay', 'instance.materialize-transfer', $this->payload)]]],
    ]]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.relay_parent', 'worker' => 'parent'])->assertOk();
    foreach (['source', 'destination'] as $key) {
        $id = $this->postJson('/api/v1/operation-executor/'.$this->parent->id.'/children', ['worker' => 'parent', 'key' => $key])->assertAccepted()->json('data.id');
        $this->{$key} = Operation::findOrFail($id);
    }
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.relay_source', 'worker' => 'source'])->assertOk();
    $path = '/api/v1/operation-executor/'.$this->source->id;
    $this->postJson($path.'/run', ['worker' => 'source', 'key' => 'capture', 'transport' => 'agent', 'name' => 'instance.transfer-source', 'input' => $producer])->assertOk();
    $this->postJson($path.'/step', ['worker' => 'source', 'key' => 'archive.done', 'name' => 'archive', 'status' => 'succeeded', 'result' => $this->source->fresh()->state['executor_runs']['capture']['result']])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'source', 'status' => 'succeeded', 'result' => $this->source->fresh()->state['executor_runs']['capture']['result']])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'test.relay_destination', 'worker' => 'destination'])->assertOk();
    $this->path = '/api/v1/operation-executor/'.$this->destination->id;
    $this->run = ['worker' => 'destination', 'key' => 'deliver', 'transport' => 'archive_relay', 'name' => 'instance.materialize-transfer', 'input' => $this->payload];
});

it('relays verified bytes privately and replays the keyed receipt without transport', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.sha256', $this->metadata['sha256']);
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    expect($this->agents->commands())->toBe(['instance.transfer-source', 'instance.read-transfer-archive', 'instance.materialize-transfer'])
        ->and($this->envelope['nonce'])->toBe($this->destination->fresh()->state['executor_effect_identity']['nonce'])
        ->and($this->sink->write('closed'))->toBeFalse()
        ->and(json_encode($this->destination->fresh()->state))->not->toContain('private archive')
        ->and(OperationKinds::supports('archive_relay'))->toBeTrue();
});

it('refuses corrupted streams before destination dispatch and closes staging', function (): void {
    $original = $this->bytes;
    $this->bytes = str_repeat('x', strlen($this->bytes));
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.archive_invalid');
    expect($this->agents->commands())->toBe(['instance.transfer-source', 'instance.read-transfer-archive'])->and($this->sink->write('closed'))->toBeFalse();
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    $this->bytes = $original;
    $this->postJson($this->path.'/run', $this->run)->assertOk();
});

it('refuses invalid source proof or limits before reading bytes', function (string $case): void {
    $state = $originalState = $this->source->fresh()->state;
    $originalInput = $this->source->input;
    if ($case === 'limit') {
        $this->family->limit = 1;
    } elseif ($case === 'deleted') {
        $this->sourceNode->delete();
    } elseif ($case === 'tampered') {
        $this->source->update(['input' => ['forged' => true]]);
    } elseif ($case === 'unfinished') {
        $this->source->update(['status' => OperationStatus::Running]);
    } else {
        $state['executor_runs']['capture'][$case === 'producer' ? 'name' : 'receipt_state'] = 'wrong';
        $this->source->update(['state' => $state]);
    }
    $this->postJson($this->path.'/run', $this->run)->assertConflict();
    expect($this->agents->commands())->toBe(['instance.transfer-source']);
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    if ($case !== 'deleted') {
        $this->family->limit = 10000;
        $this->source->update(['state' => $originalState, 'input' => $originalInput, 'status' => OperationStatus::Succeeded]);
        $this->postJson($this->path.'/run', $this->run)->assertOk();
    }
})->with(['limit', 'deleted', 'tampered', 'unfinished', 'producer', 'receipt']);

it('refuses caller changes through exact input authorization', function (): void {
    $this->run['input']['relay']['parent_operation_id']++;
    $this->postJson($this->path.'/run', $this->run)->assertStatus(422)->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->commands())->toBe(['instance.transfer-source']);
});

it('observes cancellation during download before sending any destination bytes', function (): void {
    $this->agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $this->sink = $output;
        $output->write($this->bytes);
        $this->destination->update(['cancel_requested_at' => now()]);

        return $this->metadata;
    });
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.cancelled');
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code', 'operation.cancelled');
    expect($this->agents->commands())->toBe(['instance.transfer-source', 'instance.read-transfer-archive'])->and($this->sink->write('closed'))->toBeFalse();
});

it('recovers a lost upload reply without downloading again after source deletion', function (): void {
    $this->agents->answer('instance.materialize-transfer', function (): array {
        throw new RuntimeException('private transport detail');
    });
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $identity = $this->destination->fresh()->state['executor_effect_identity'];
    $this->sourceNode->delete();
    $this->agents->answer('agent.effect-receipt', ['command' => 'instance.materialize-transfer', 'effect_key' => 'op-'.$this->destination->id.'.deliver', 'state' => 'succeeded',
        'sanitized' => true, 'effect_nonce' => $identity['nonce'], 'effect_created_at' => $identity['created_at'],
        'result' => ['ok' => true, 'data' => $this->metadata], 'result_digest' => str_repeat('c', 64),
        'exit_code' => 0, 'finished_at' => '2026-10-29T02:00:00Z']);
    $this->getJson($this->path.'/receipts/deliver')->assertOk()->assertJsonPath('data.state', 'succeeded')
        ->assertJsonPath('data.result.sha256', $this->metadata['sha256']);
    $this->getJson($this->path.'/receipts/deliver')->assertOk();
    expect($this->agents->commands())->toBe(['instance.transfer-source', 'instance.read-transfer-archive', 'instance.materialize-transfer', 'agent.effect-receipt']);
});

it('closes staging after a failed source read without exposing exception text', function (): void {
    $this->agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $this->sink = $output;
        $output->write('partial');
        throw new RuntimeException('private archive diagnostic');
    });
    $response = $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.archive_unavailable');
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    expect($response->getContent())->not->toContain('private archive diagnostic')->and($this->sink->write('closed'))->toBeFalse();
});

it('retries a failed source read without admitting a second destination effect', function (): void {
    $this->agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $output->write('partial');
        throw new RuntimeException('lost source read');
    });
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.archive_unavailable');
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    $this->agents->answer('instance.read-transfer-archive', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
        $output->write($this->bytes);

        return $this->metadata;
    });
    $this->postJson($this->path.'/run', $this->run)->assertOk();
    expect($this->agents->commands())->toBe(['instance.transfer-source', 'instance.read-transfer-archive', 'instance.read-transfer-archive', 'instance.materialize-transfer']);
});

it('binds the reader path to the proven producer archive before reading', function (): void {
    $this->family->archive = '/tmp/orbit-transfer-other.tar';
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.archive_forbidden');
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    expect($this->agents->commands())->toBe(['instance.transfer-source']);
    $this->family->archive = '/tmp/orbit-transfer-fixture.tar';
    $this->postJson($this->path.'/run', $this->run)->assertOk();
});

it('never clears earlier upload uncertainty after a later preparation refusal', function (): void {
    $this->family->resumable = true;
    $this->agents->answer('instance.materialize-transfer', function (): array {
        throw new RuntimeException('lost upload reply');
    });
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $this->family->limit = 1;
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => true, 'result' => null]);
    expect($this->agents->commands())->toBe(['instance.transfer-source', 'instance.read-transfer-archive', 'instance.materialize-transfer']);
});

it('binds a SQLite reader path to the producer path', function (): void {
    $this->family->reader = 'instance.read-sqlite-snapshot';
    $this->family->readInput = ['path' => '/tmp/orbit-sqlite-wrong.sqlite', ...$this->metadata];
    $state = $this->source->fresh()->state;
    $state['executor_runs']['capture']['result']['path'] = '/tmp/orbit-sqlite-proven.sqlite';
    $this->source->update(['state' => $state]);
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.archive_forbidden');
    expect($this->destination->fresh()->state['executor_runs']['deliver'])->toMatchArray(['started' => false, 'result' => null]);
    expect($this->agents->commands())->toBe(['instance.transfer-source']);
});

it('refuses staging without enough free disk space and reserve before reading', function (): void {
    $free = disk_free_space(sys_get_temp_dir());
    if ($free === false || $free < 1 || $free > (1 << 40)) {
        $this->markTestSkipped('This filesystem cannot exercise the native one-TiB staging bound.');
    }
    $bytes = (int) $free;
    $this->family->limit = $bytes;
    $state = $this->source->fresh()->state;
    $state['executor_runs']['capture']['result']['bytes'] = $bytes;
    $this->source->update(['state' => $state]);
    $input = $this->payload;
    $input['input']['bytes'] = $bytes;
    try {
        app(OperationArchiveRelayTransport::class)->run($this->destination, $this->run, $input);
        $this->fail('Insufficient staging space must refuse.');
    } catch (OperationDispatchNotStarted $exception) {
        expect($exception->errorCode)->toBe('operation.archive_unavailable');
    }
    expect($this->agents->commands())->toBe(['instance.transfer-source']);
});
