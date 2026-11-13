<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

function recordOnlyNode(): Node
{
    SwampNodeFixture::ensure();

    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'record-only', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
}

/** @return object{started: list<int>} */
function recordOnlyDispatch(): object
{
    app()->instance(SwampDispatch::class, $dispatch = new class
    {
        /** @var list<int> */
        public array $started = [];

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
        }
    });

    return $dispatch;
}

it('deletes a role that never touched the Node and starts no run', function (): void {
    Http::preventStrayRequests();
    $dispatch = recordOnlyDispatch();
    $node = recordOnlyNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed',
        'failed_step' => 'converge:Require supported Ubuntu', 'error_code' => 'role.os_unsupported', 'node_untouched' => true]);

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, force: true);

    expect($operation->kind)->toBe('node.role_remove')
        ->and($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toMatchArray(['role' => 'database', 'assignment_id' => $assignment->id, 'role_removed' => true])
        ->and($operation->input)->not->toHaveKey('_role_remove')
        ->and(NodeRole::query()->whereKey($assignment->id)->exists())->toBeFalse()
        ->and($dispatch->started)->toBe([]);
    Http::assertNothingSent();
});

it('keeps the native withdrawal for a role that may have changed the Node', function (bool $changedNode): void {
    Http::preventStrayRequests();
    $dispatch = recordOnlyDispatch();
    $node = recordOnlyNode();
    // A row from before the migration holds the column default, and a failed first step that changed the Node holds false.
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed',
        'failed_step' => $changedNode ? 'converge:Apply role prerequisites' : 'converge:Require supported Ubuntu',
        'error_code' => 'node_role.convergence_failed']);

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, force: true);

    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input)->toHaveKey('_role_remove')
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($dispatch->started)->toBe([$operation->id]);
})->with(['a failure after the first change' => [true], 'a row from before the migration' => [false]]);

it('carries the fact through a convergence claim only from a role that never touched the Node', function (bool $untouched): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed',
        'failed_step' => 'converge:Require supported Ubuntu', 'error_code' => 'role.os_unsupported', 'node_untouched' => $untouched]);

    $assignment->claimConvergence();

    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Provisioning)
        ->and($assignment->node_untouched)->toBe($untouched)
        ->and($assignment->neverTouchedNode())->toBeFalse();
})->with(['never touched' => [true], 'touched before' => [false]]);

it('adds the role again after the record-only removal', function (): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();
    $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed',
        'failed_step' => 'converge:Read the OS release', 'error_code' => 'node.unreachable', 'node_untouched' => true]);
    app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, force: true);

    $operation = app(AdmitNodeRoleOperationAction::class)->add($node->refresh(), RoleName::Database);

    expect($operation->kind)->toBe('node.role_add')
        ->and($node->roles()->where('role', RoleName::Database)->firstOrFail()->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->roles()->where('role', RoleName::Database)->firstOrFail()->node_untouched)->toBeTrue();
});

it('keeps the removal refusals ahead of the record-only path', function (): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();
    $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed', 'node_untouched' => true]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Swamp, force: true))
        ->toThrow(NodeRoleValidationException::class);
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database))
        ->toThrow(NodeRoleValidationException::class);

    expect($node->roles()->count())->toBe(1)
        ->and(Operation::query()->count())->toBe(0);
});

/** Ends a role add or converge Operation the way its Swamp run would: `$step` null means the run succeeded. */
function recordOnlyRunEnds(Operation $operation, string $status, ?string $step): void
{
    $operation->forceFill(['status' => 'running', 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'fixture-run-'.$operation->id]])->save();
    $steps = $step === null ? ['Check the role' => ['status' => 'succeeded']] : [$step => ['status' => 'failed']];
    app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => 'fixture-run-'.$operation->id, 'status' => $status,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => $steps, 'summary' => ['failed_steps' => $step === null ? [] : [$step]]]]);
}

/** Runs a first add, then each `--converge` retry, and returns the assignment after the last run. */
function recordOnlyRuns(Node $node, string ...$failedSteps): NodeRole
{
    foreach ($failedSteps as $index => $step) {
        $operation = app(AdmitNodeRoleOperationAction::class)->add($node->refresh(), RoleName::Database, converge: $index > 0);
        recordOnlyRunEnds($operation, 'failed', $step);
    }

    return $node->roles()->where('role', RoleName::Database)->sole();
}

it('keeps the fact only while every run failed at a read-only step', function (array $failedSteps, bool $untouched): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();

    $assignment = recordOnlyRuns($node, ...$failedSteps);

    expect($assignment->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->neverTouchedNode())->toBe($untouched);
})->with([
    'first add fails at the OS check' => [['Require supported Ubuntu'], true],
    'a retry fails at the OS check again' => [['Require supported Ubuntu', 'Read the OS release'], true],
    'a run changed the Node, then a retry fails at the OS check' => [['Apply the role on the Node', 'Read the OS release'], false],
    'a later run changed the Node, then a third run fails at the OS check' => [['Require supported Ubuntu', 'Apply the role on the Node', 'Read the OS release'], false],
]);

it('withdraws a role whose earlier run changed the Node although its last run failed at the OS check', function (): void {
    Http::preventStrayRequests();
    $dispatch = recordOnlyDispatch();
    $node = recordOnlyNode();
    $assignment = recordOnlyRuns($node, 'Apply the role on the Node', 'Read the OS release');

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node->refresh(), RoleName::Database, force: true);

    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input)->toHaveKey('_role_remove')
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($dispatch->started)->toContain($operation->id);
});

it('removes from the record alone after two runs that both failed at the OS check', function (): void {
    Http::preventStrayRequests();
    $dispatch = recordOnlyDispatch();
    $node = recordOnlyNode();
    $assignment = recordOnlyRuns($node, 'Require supported Ubuntu', 'Require supported Ubuntu');

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node->refresh(), RoleName::Database, force: true);

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and(NodeRole::query()->whereKey($assignment->id)->exists())->toBeFalse()
        ->and($dispatch->started)->not->toContain($operation->id);
});

it('clears the fact when a stale provisioning claim is claimed again', function (): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();
    $lost = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::Database);
    // The first run's result never arrives: its Operation ends without a projection and the claim goes stale.
    $lost->forceFill(['status' => 'failed', 'lock_key' => null])->save();
    $assignment = $node->roles()->where('role', RoleName::Database)->sole();
    NodeRole::query()->whereKey($assignment->id)->update(['updated_at' => now()->subSeconds(NodeRole::StaleClaimSeconds + 1)]);

    $retry = app(AdmitNodeRoleOperationAction::class)->add($node->refresh(), RoleName::Database, converge: true);
    recordOnlyRunEnds($retry, 'failed', 'Read the OS release');

    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->neverTouchedNode())->toBeFalse();
});

it('refuses --offline on a role that never touched the Node and keeps the row', function (): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed',
        'failed_step' => 'converge:Require supported Ubuntu', 'error_code' => 'role.os_unsupported', 'node_untouched' => true]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, force: true, offline: true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('operation.kind_unavailable'));

    expect(NodeRole::query()->whereKey($assignment->id)->exists())->toBeTrue()
        ->and(Operation::query()->count())->toBe(0);
});
