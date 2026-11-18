<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationVersionCleanup;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofVerifyProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'version-test', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '10.44.0.8', 'user' => 'orbit']));
});

function operationVersionsForTest(array $policy, array $custom = []): DirectOperation
{
    config(['operation_versions' => $policy]);
    $provider = new class($custom) implements DirectOperationContribution
    {
        public function __construct(private array $declarations) {}

        public function definitions(): array
        {
            return $this->declarations;
        }
    };
    $direct = new DirectOperation([$provider]);
    app()->instance(DirectOperation::class, $direct);

    return $direct;
}

it('pins the declared workflow, private inputs and projection across upgrades and preserves an idempotent replay after the fence', function (): void {
    $kind = 'fixture.versioned';
    $historical = ['workflow' => 'proof-fleet-verify-v1', 'projection' => ProofCommandProjection::class];
    $current = ['workflow' => 'proof-fleet-verify-v2', 'projection' => ProofVerifyProjection::class];
    operationVersionsForTest([], [$kind => $historical]);
    $store = app(OperationStore::class);
    $key = app(OperationIdempotency::class);
    $key->kind = $kind;
    $key->key = 'version-replay';
    $key->fingerprint = str_repeat('c', 64);
    $operation = $store->create($kind, 'node', 1, $this->node->id, [], 'operator', null)->refresh();
    expect($operation->operation_version)->toBe(1);
    $direct = operationVersionsForTest([$kind => ['current' => 2, 'retained' => [1 => $historical], 'deprecated' => [1]]], [$kind => $current]);
    expect($direct->definition($operation))->toBe($historical)->and($direct->workflowFor($operation))->toBe($historical['workflow'])
        ->and($direct->projection($operation))->toBeInstanceOf(ProofCommandProjection::class);
    $this->artisan('operations:check-cleanup', ['--fence-only' => true, '--json' => true])->expectsOutput('{"fenced":true}')->assertSuccessful();
    expect(DB::table('deprecated_operation_versions')->where('kind', $kind)->value('eligible_at'))->toBeNull();
    expect($store->create($kind, 'node', 1, $this->node->id, [], 'operator', null)->id)->toBe($operation->id);
    $key->kind = $key->key = $key->fingerprint = null;
    $new = $store->create($kind, 'node', 2, $this->node->id, [], 'operator', null);
    expect($new->operation_version)->toBe(2)->and($direct->workflowFor($new))->toBe($current['workflow']);
    // The previous binary writes no version column and receives the database's v1 default.
    expect(fn () => DB::table('operations')->insert(['kind' => $kind, 'target_type' => 'node', 'target_id' => 3, 'status' => 'queued',
        'node_id' => $this->node->id, 'input' => '{}', 'state' => '{}']))->toThrow(QueryException::class, 'operation.version_deprecated');
    expect(fn () => $operation->forceFill(['operation_version' => 2])->save())->toThrow(QueryException::class, 'operation.version_immutable');
    operationVersionsForTest([], [$kind => $historical]);
    expect(app(DirectOperation::class)->definition($new))->toBeNull();
    $worker = new StartAdmittedSwampRun($new->id);
    $worker->handle(app(StartSwampRun::class), app(ExpireOperationAction::class));
    $worker->failed(new RuntimeException('Old worker stopped'));
    app(StartSwampRun::class)->start($new);
    Bus::assertDispatched(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $new->id && $job->delay !== null);
    expect($new->refresh()->status->value)->toBe('queued')->and($new->attempts)->toBe(0);
    expect($store->fenceAdmission($kind, 'version-replay', str_repeat('c', 64), 'node', 1, $this->node->id, [], 'operator', 'receipt')->id)->toBe($operation->id);
    expect(fn () => $store->create($kind, 'node', 3, $this->node->id, [], 'operator', null))->toThrow(ResourceOperationException::class);
});

it('defers removal for pending children and unproven native terminals until the last affected work drains', function (): void {
    $store = app(OperationStore::class);
    $queued = $store->create('proof.command', 'task_group', 1, $this->node->id, [], 'operator', null);
    $waiting = $store->create('proof.verify', 'node', 2, $this->node->id, [], 'operator', null);
    $waiting->forceFill(['status' => 'waiting'])->save();
    $late = $store->create('proof.verify', 'node', 3, $this->node->id, [], 'operator', null);
    $late->forceFill(['status' => 'failed', 'error_code' => 'operation.timed_out', 'state' => ['native_timeout' => ['native_terminal_status' => 'unproven']]])->save();
    $store->create('proof.run', 'task_group', 4, $this->node->id, [], 'operator', null)->forceFill(['status' => 'succeeded'])->save();
    operationVersionsForTest(['proof.verify' => ['deprecated' => [1]]]);
    $cleanup = app(OperationVersionCleanup::class);
    expect($cleanup->check()['versions'][0]['outstanding'])->toBe(3);
    $this->artisan('operations:check-cleanup', ['--json' => true])->assertExitCode(75);
    expect(fn () => $store->create('proof.command', 'task_group', 6, $this->node->id, [], 'operator', null))->toThrow(ResourceOperationException::class);
    // The old binary lacks child_versions. An independently persisted transitive edge fences it as well.
    expect(fn () => DB::table('operations')->insert(['kind' => 'proof.run', 'target_type' => 'task_group', 'target_id' => 9, 'status' => 'queued',
        'node_id' => $this->node->id, 'input' => '{}', 'state' => '{}']))->toThrow(QueryException::class, 'operation.version_deprecated');
    foreach ([$queued, $waiting] as $run) {
        $run->forceFill(['status' => 'succeeded'])->save();
    }
    expect($cleanup->check()['versions'][0]['outstanding'])->toBe(1);
    $late->forceFill(['state' => ['native_timeout' => ['native_terminal_status' => 'failed']]])->save();
    $this->artisan('operations:check-cleanup', ['--json' => true])->assertSuccessful();
    expect(fn () => $late->forceFill(['status' => 'running'])->save())->toThrow(QueryException::class, 'operation.version_retired');
    expect($cleanup->check()['versions'][0])->toBe(['kind' => 'proof.verify', 'version' => 1, 'eligible' => true, 'outstanding' => 0]);
});

it('rejects version declarations that reuse a native workflow identity', function (): void {
    $old = app(DirectOperation::class)->definition('proof.verify');
    expect(fn () => operationVersionsForTest(['proof.verify' => ['current' => 2, 'retained' => [1 => $old]]]))->toThrow(LogicException::class, 'distinct native workflow identities');
});

it('starts the deferred shard of its old waiting proof using the parent pin after the new admission fence', function (): void {
    $store = app(OperationStore::class);
    $parent = $store->create('proof.run', 'task_group', 1, $this->node->id, [], 'operator', null);
    $parent->forceFill(['status' => 'waiting'])->save();
    $old = app(DirectOperation::class)->definition('proof.command');
    $old['workflow'] = 'operation-proof-command-v1';
    $direct = operationVersionsForTest(['proof.command' => ['current' => 2, 'retained' => [1 => $old], 'deprecated' => [1]]]);
    $this->artisan('operations:check-cleanup', ['--fence-only' => true])->assertSuccessful();
    $start = app(StartOperationAction::class);
    $shard = $start->execute('proof.command', 'task_group', 8, $this->node->id, ['proof_operation_id' => $parent->id],
        'proof', null, start: false, parent: $parent);
    expect($shard->operation_version)->toBe(1)->and($shard->parent_operation_id)->toBe($parent->id)
        ->and($direct->workflowFor($shard))->toBe('operation-proof-command-v1');
    $new = $start->execute('proof.command', 'task_group', 9, $this->node->id, [], 'operator', null, start: false);
    expect($new->operation_version)->toBe(2);
    expect(fn () => $start->execute('proof.command', 'task_group', 8, $this->node->id, ['proof_operation_id' => 999],
        'proof', null, start: false, parent: $parent))->toThrow(ResourceOperationException::class);
    $parent->forceFill(['status' => 'succeeded'])->save();
    expect(fn () => $start->execute('proof.command', 'task_group', 10, $this->node->id, ['proof_operation_id' => $parent->id],
        'proof', null, start: false, parent: $parent))->toThrow(ResourceOperationException::class);
    expect(app(OperationVersionCleanup::class)->check()['versions'][0]['outstanding'])->toBe(1);
});

it('counts every affected row beyond one chunk and excludes unrelated work', function (): void {
    $rows = [];
    foreach (range(1, 1005) as $id) {
        $rows[] = ['kind' => 'proof.verify', 'target_type' => 'node', 'target_id' => $id, 'node_id' => $this->node->id,
            'input' => '{}', 'state' => '{}', 'status' => 'queued'];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('operations')->insert($chunk);
    }
    DB::table('operations')->insert([...$rows[0], 'kind' => 'unrelated.work']);
    operationVersionsForTest(['proof.verify' => ['deprecated' => [1]]]);
    expect(app(OperationVersionCleanup::class)->check()['versions'][0]['outstanding'])->toBe(1005);
});
