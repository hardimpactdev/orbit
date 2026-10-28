<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationTransports;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final class ReceiptTestSecret implements OperationSecretSource
{
    public int $resolves = 0;

    public bool $rotates = false;

    public function name(): string
    {
        return 'receipt.test';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->target_id;
    }

    public function resolve(Operation $operation, int $ownerId, string $key, string $parameter): OperationSecretValue
    {
        $this->resolves++;

        return new OperationSecretValue('receipt-private-value'.($this->rotates ? '-'.$this->resolves : ''));
    }
}

final class ReceiptTestExecution implements OperationCompletion, OperationFamilyExecution
{
    public function context(Operation $operation): array
    {
        return ['descriptors' => $operation->input['descriptors']];
    }

    public function input(Operation $operation, array $run): array
    {
        return $operation->input['descriptors'][0]['input'];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) {
            return $receipt;
        }
        expect($receipt['echo'])->toBe('[REDACTED]');
        expect($receipt['undeclared'])->toBe('private-log');
        expect($receipt['password'])->toBe('classification-marker');
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        $operation->state = [...($operation->state ?? []), 'projections' => ($operation->state['projections'] ?? 0) + 1];

        $operation->state = [...($operation->state ?? []), 'source_profile' => $receipt['undeclared'] === 'private-log' ? 'classified' : 'unknown'];
        if (($operation->input['reject_projection'] ?? false) === true) {
            $operation->save();
            throw new ResourceOperationException('test.projection_failed', 'Projection refused.', 409);
        }

        return [...$receipt, 'projected' => true];
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

final class ReceiptTestTransport implements OperationEffectTransport
{
    public int $runs = 0;

    public int $reads = 0;

    public array $run = [];

    public array $receipt = [];

    public bool $loseReply = true;

    public function schema(Operation $operation, array $run): array|bool
    {
        return ['type' => 'object', 'properties' => ['token' => ['type' => 'string']], 'required' => ['token'], 'additionalProperties' => false];
    }

    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    public function run(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $this->runs++;
        $this->run = $run;
        expect($run['input']['token'])->toBe(['$secret' => 'receipt.test', 'id' => $operation->target_id]);
        $masks = EffectReceiptMetadata::fromRun($run)->envelope()['redactions'];
        expect($masks)->toContain($input['token']);
        $this->receipt['result']['data']['echo'] = $input['token'];
        $this->receipt['result'] = app(OperationSecrets::class)->redact($this->receipt['result'], $masks);
        $this->receipt['sanitized'] = true;
        if ($this->loseReply) {
            throw new RuntimeException('uncertain transport with receipt-private-value');
        }

        return $this->normalize($this->receipt['result']);
    }

    public function receipt(Operation $operation, array $run, #[SensitiveParameter] array $input): array
    {
        $this->reads++;
        expect($input)->toBe($run['input']);
        expect($run['effect_key'])->toBe('op-'.$operation->id.'.effect');
        $receipt = $this->receipt;
        $receipt['effect_key'] = $receipt['effect_key'] ?? $run['effect_key'];
        if ($receipt['result'] !== null) {
            $receipt['result'] = $this->normalize($receipt['result']);
        }

        return $receipt;
    }

    private function normalize(array $result): array
    {
        return ['ready' => $result['outcome'] === 'succeeded', 'echo' => $result['data']['echo'], 'undeclared' => 'private-log', 'password' => 'classification-marker'];
    }
}

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->target = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->services->wireguard_ip]);
    $this->source = new ReceiptTestSecret;
    app()->instance(ReceiptTestSecret::class, $this->source);
    app()->tag([ReceiptTestSecret::class], OperationSecretSource::class);
    $this->transport = new ReceiptTestTransport;
    app()->instance(ReceiptTestTransport::class, $this->transport);
    app()->instance(OperationTransports::class, new OperationTransports(['receipt-test' => ReceiptTestTransport::class]));
    $kind = ['executor' => 'swamp', 'execution' => ReceiptTestExecution::class, 'completion' => ReceiptTestExecution::class,
        'runs' => [['transport' => 'receipt-test', 'name' => 'test.effect', 'secrets' => ['token' => 'receipt.test'], 'resumable' => false]],
        'completion_contract' => ['step' => 'effect', 'transport' => 'receipt-test', 'name' => 'test.effect', 'result' => ['ready' => true]],
        'result_fields' => ['ready', 'echo', 'projected', 'error_code']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'test.receipts' => $kind]));
    $this->run = ['worker' => 'worker', 'key' => 'effect', 'transport' => 'receipt-test', 'name' => 'test.effect',
        'input' => ['token' => ['$secret' => 'receipt.test', 'id' => $this->target->id]]];
    $this->operation = Operation::query()->create(['kind' => 'test.receipts', 'target_type' => 'node', 'target_id' => $this->target->id, 'node_id' => $this->target->id,
        'status' => OperationStatus::Running, 'worker' => 'worker', 'sequence' => 1, 'state' => ['executor_lease_node_id' => $this->services->id],
        'input' => ['descriptors' => [[...array_diff_key($this->run, ['worker' => true]), 'step' => 'effect', 'resumable' => false, 'required' => true]]]]);
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    $this->transport->receipt = ['sanitized' => true, 'state' => 'succeeded', 'exit_code' => 0, 'result' => ['outcome' => 'succeeded', 'data' => ['echo' => 'receipt-private-value']], 'finished_at' => '2026-10-28T20:00:00Z'];
});

it('recovers the normalized filtered receipt and applies its family projection once without another effect', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502)->assertJsonPath('error.code', 'operation.execution_failed');
    $response = $this->getJson($this->path.'/receipts/effect')->assertOk()
        ->assertJsonPath('data.effect_key', 'op-'.$this->operation->id.'.effect')->assertJsonPath('data.state', 'succeeded')
        ->assertJsonPath('data.exit_code', 0)->assertJsonPath('data.result', ['ready' => true, 'echo' => '[REDACTED]', 'projected' => true])
        ->assertJsonMissingPath('data.result.undeclared');
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.result', $response->json('data.result'));
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data', $response->json('data.result'));
    expect($this->transport->runs)->toBe(1)->and($this->transport->reads)->toBe(1);
    expect($this->operation->refresh()->state['projections'])->toBe(1);
    expect($this->operation->state['source_profile'])->toBe('classified');
    expect($this->operation->state['executor_lease_node_id'])->toBe($this->services->id);
    expect($this->source->resolves)->toBe(1);
    expect(json_encode($this->operation->toArray()))->not->toContain('receipt-private-value');
    expect($this->operation->state['executor_runs']['effect']['input'])->toBe($this->run['input']);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'done', 'name' => 'effect', 'status' => 'succeeded', 'result' => $response->json('data.result')])->assertOk();
});

it('keeps running and unknown receipts unresolved and never projects or invokes an effect while reading', function (string $state): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->transport->receipt = ['state' => $state, 'exit_code' => null, 'result' => null, 'finished_at' => null];
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.state', $state)->assertJsonPath('data.result', null);
    expect($this->operation->refresh()->state['executor_runs']['effect']['result'])->toBeNull();
    expect($this->transport->runs)->toBe(1);
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'forged', 'name' => 'effect', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertConflict();
})->with(['running', 'unknown']);

it('returns unknown for an admitted descriptor with no run and rejects undeclared step keys', function (): void {
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.state', 'unknown');
    $this->getJson($this->path.'/receipts/arbitrary')->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->transport->runs)->toBe(0)->and($this->transport->reads)->toBe(0);
});

it('keeps the same public shape for failed target receipts', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->transport->receipt['state'] = 'failed';
    $this->transport->receipt['exit_code'] = 7;
    $this->transport->receipt['result']['outcome'] = 'failed';
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.exit_code', 7)->assertJsonPath('data.result.ready', false);
});

it('refuses another executor peer and caller-selected target or worker query', function (): void {
    $this->getJson($this->path.'/receipts/effect?node_id=123')->assertUnprocessable();
    $this->getJson($this->path.'/receipts/effect?worker=other')->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => $this->target->wireguard_ip]);
    $this->getJson($this->path.'/receipts/effect')->assertForbidden();
    expect($this->transport->runs)->toBe(0)->and($this->transport->reads)->toBe(0);
});

it('rejects altered admitted input before reading target evidence', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $state = $this->operation->refresh()->state;
    $state['executor_runs']['effect']['input']['token']['id']++;
    $this->operation->update(['state' => $state]);
    $this->getJson($this->path.'/receipts/effect')->assertUnprocessable();
    expect($this->transport->reads)->toBe(0);
});

it('rejects a mismatched target key or secret-bearing receipt metadata', function (string $field, mixed $value): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->transport->receipt[$field] = $value;
    $this->getJson($this->path.'/receipts/effect')->assertStatus(502)->assertJsonPath('error.code', 'operation.receipt_invalid');
    expect($this->operation->refresh()->state['executor_runs']['effect']['result'])->toBeNull();
})->with([['effect_key', 'op-999.effect'], ['finished_at', 'receipt-private-value'], ['exit_code', 'receipt-private-value']]);

it('requires an active claim to recover new evidence and permits cached reads after completion', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->travel(121)->seconds();
    $this->getJson($this->path.'/receipts/effect')->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    expect($this->transport->reads)->toBe(0);
    $this->operation->update(['updated_at' => now(), 'worker' => 'new-worker']);
    $result = $this->getJson($this->path.'/receipts/effect')->assertOk()->json('data.result');
    $this->operation->update(['status' => OperationStatus::Succeeded]);
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.result', $result);
    expect($this->transport->reads)->toBe(1)->and($this->operation->refresh()->state['projections'])->toBe(1);
});

it('does not mint another protected value when recovering an original sanitized receipt', function (): void {
    $this->source->rotates = true;
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    expect($this->source->resolves)->toBe(1);
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.result.echo', '[REDACTED]');
    expect($this->source->resolves)->toBe(1);
    expect(json_encode($this->operation->refresh()->toArray()))->not->toContain('receipt-private-value');
});
it('fails closed when target sanitization is unproven', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->transport->receipt['sanitized'] = false;
    $this->getJson($this->path.'/receipts/effect')->assertConflict()->assertJsonPath('error.code', 'operation.receipt_unproven');
    expect($this->operation->refresh()->state['executor_runs']['effect']['result'])->toBeNull();
    expect($this->source->resolves)->toBe(1);
});
it('rolls back the family projection and receipt together when classification refuses', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $this->operation->update(['input' => [...$this->operation->input, 'reject_projection' => true]]);
    $this->getJson($this->path.'/receipts/effect')->assertConflict()->assertJsonPath('error.code', 'test.projection_failed');
    expect($this->operation->refresh()->state['executor_runs']['effect']['result'])->toBeNull();
    expect($this->operation->state)->not->toHaveKey('projections');
    expect($this->source->resolves)->toBe(1);
});
it('fences another configured executor peer even when it knows the worker name', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $peer = Node::query()->create(['name' => 'worker-peer', 'public_ssh_host' => '192.0.2.18', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.18', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $gateway = Node::query()->where('name', 'gateway')->sole();
    $peer->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [$peer->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);
    $this->getJson($this->path.'/receipts/effect')->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    $this->postJson($this->path.'/run', $this->run)->assertConflict()->assertJsonPath('error.code', 'operation.lease_lost');
    expect($this->transport->reads)->toBe(0)->and($this->source->resolves)->toBe(1);
});

it('does not bypass the explicit replay policy for a keyed effect after an unknown receipt', function (): void {
    $this->postJson($this->path.'/run', $this->run)->assertStatus(502);
    $terminal = $this->transport->receipt;
    $this->transport->receipt = ['state' => 'unknown', 'sanitized' => false, 'exit_code' => null, 'result' => null, 'finished_at' => null];
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.state', 'unknown');
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.error_code', 'operation.execution_uncertain');
    expect($this->transport->runs)->toBe(1)->and($this->source->resolves)->toBe(1);
    expect($this->operation->refresh()->state['executor_runs']['effect']['result'])->toBeNull();
    expect($this->operation->state)->not->toHaveKey('projections');
    $this->transport->receipt = $terminal;
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.result.projected', true);
    expect($this->operation->refresh()->state['projections'])->toBe(1);
    expect($this->transport->runs)->toBe(1)->and($this->source->resolves)->toBe(1);
});

it('processes the same raw safe fields before redaction for live replies and recovered replies', function (): void {
    $this->transport->loseReply = false;
    $response = $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonMissingPath('data.password');
    $this->getJson($this->path.'/receipts/effect')->assertOk()->assertJsonPath('data.result', $response->json('data'));
    expect($this->operation->refresh()->state['source_profile'])->toBe('classified');
    expect($this->operation->state['projections'])->toBe(1);
    expect($this->source->resolves)->toBe(1);
});
