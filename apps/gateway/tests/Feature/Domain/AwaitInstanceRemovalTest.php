<?php

declare(strict_types=1);

use App\Actions\Instances\AwaitInstanceRemovalAction;
use App\Actions\Instances\DestroyInstanceAction;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'proof-dev', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'type' => 'monorepo',
        'repository_url' => 'https://example.test/proof.git', 'root' => 'public', 'default_branch' => 'main']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'isolated-proof', 'status' => InstanceState::Active, 'source_layout' => 'checkout',
        'checkout_path' => '/srv/apps/proof/isolated-proof', 'branch' => 'proof', 'starting_commit' => str_repeat('c', 40)]);
    $this->removal = InstanceRemoval::query()->create(['id' => 'lifecycle-removal-proof',
        'requested_instance_id' => $this->instance->id, 'requested_name' => 'isolated-proof', 'force' => false,
        'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation]);
    $this->member = $this->removal->members()->create(['position' => 0, 'instance_id' => $this->instance->id,
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'isolated-proof', 'environment' => 'development',
        'source_layout' => 'checkout', 'repository_identity' => $project->repository_identity,
        'checkout_path' => $this->instance->checkout_path, 'root' => 'public', 'branch' => 'proof',
        'starting_commit' => $this->instance->starting_commit, 'source_commit' => $this->instance->starting_commit,
        'common_repository_path' => $this->instance->checkout_path, 'source_identity' => 'local-proof:1',
        'linked_worktree_paths' => [$this->instance->checkout_path], 'source_digest' => str_repeat('b', 64)]);
    $this->operation = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $node->id, 'status' => OperationStatus::Succeeded, 'sequence' => 1,
        'input' => ['instance_id' => $this->instance->id, 'removal_id' => $this->removal->id, 'members' => []],
        'result' => ['lifecycle_complete' => true, 'instance_id' => $this->instance->id, 'removal_id' => $this->removal->id]]);
    $this->completeRemoval = function (): void {
        $this->instance->update(['status' => InstanceState::Removing]);
        $this->member->update(['source_prepared_at' => now(), 'route_cleared_at' => now(), 'route_outcome' => 'none',
            'source_finalized_at' => now(), 'finalization_receipt' => str_repeat('d', 64), 'runtime_cleaned_at' => now()]);
        $this->instance->delete();
        $this->member->update(['row_deleted_at' => now()]);
        $this->removal->update(['status' => InstanceRemovalStatus::Completed, 'current_step' => null]);
    };
    // Match a retained caller after its admission transaction commits.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
});

it('returns the matching completed removal after the same Operation settles', function (): void {
    $this->operation->update(['status' => OperationStatus::Waiting]);
    Sleep::fake();
    try {
        Sleep::whenFakingSleep(function (): void {
            ($this->completeRemoval)();
            $this->operation->update(['status' => OperationStatus::Succeeded]);
        });
        $result = app(AwaitInstanceRemovalAction::class)->execute($this->operation);
        expect($result->id)->toBe($this->removal->id)->and($result->members->sole()->instance_id)->toBe($this->instance->id);
        Sleep::assertSleptTimes(1);
        expect($this->operation->refresh()->attempts)->toBe(0);
    } finally {
        Sleep::fake(false);
    }
});

it('does not turn a failed or cancelled Operation into a completed removal', function (OperationStatus $status, ?string $code): void {
    $this->operation->update(['status' => $status, 'error_code' => $code]);
    try {
        app(AwaitInstanceRemovalAction::class)->execute($this->operation);
        test()->fail('The caller must not proceed after an unsuccessful removal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code ?? 'operation.cancelled')
            ->and($exception->details)->toBe(['operation_id' => $this->operation->id]);
    }
})->with([[OperationStatus::Failed, 'instance.source_busy'], [OperationStatus::Cancelled, null]]);

it('refuses mismatched or incomplete removal receipts', function (string $change): void {
    if ($change !== 'incomplete') {
        ($this->completeRemoval)();
    }
    match ($change) {
        'kind' => $this->operation->update(['kind' => 'instance.create']),
        'target' => $this->operation->update(['target_id' => 502]),
        'result' => $this->operation->update(['result' => [...$this->operation->result, 'removal_id' => 'another-removal']]),
        'incomplete' => null,
    };
    try {
        app(AwaitInstanceRemovalAction::class)->execute($this->operation);
        test()->fail('Only the complete matching removal can be returned.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('operation.completion_unproven');
    }
})->with(['kind', 'target', 'result', 'incomplete']);

it('keeps an unfinished removal running after the retained caller deadline', function (): void {
    $this->operation->update(['status' => OperationStatus::Waiting]);
    expect(fn () => app(AwaitInstanceRemovalAction::class)->execute($this->operation, 1))
        ->toThrow(ResourceOperationException::class, 'The Operation has not ended within the caller wait.');
    expect($this->operation->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($this->operation->cancel_requested_at)->toBeNull()->and($this->member->refresh()->row_deleted_at)->toBeNull();
});

it('refuses a retained caller transaction before admitting another removal', function (): void {
    $count = Operation::query()->count();
    DB::beginTransaction();
    try {
        app(DestroyInstanceAction::class)->execute($this->instance, false);
        test()->fail('The caller must commit before admission and wait.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('operation.await_in_transaction');
    } finally {
        DB::rollBack();
    }
    expect(Operation::query()->count())->toBe($count)->and($this->instance->refresh()->status)->toBe(InstanceState::Active);
});
