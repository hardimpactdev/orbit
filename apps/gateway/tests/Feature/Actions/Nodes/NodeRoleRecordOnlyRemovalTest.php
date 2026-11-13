<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
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

it('forgets the fact once a later run claims the assignment', function (): void {
    Http::preventStrayRequests();
    recordOnlyDispatch();
    $node = recordOnlyNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'failed',
        'failed_step' => 'converge:Require supported Ubuntu', 'error_code' => 'role.os_unsupported', 'node_untouched' => true]);

    $assignment->claimConvergence();

    expect($assignment->refresh()->node_untouched)->toBeFalse()
        ->and($assignment->neverTouchedNode())->toBeFalse();
});

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
        ->and($node->roles()->where('role', RoleName::Database)->firstOrFail()->node_untouched)->toBeFalse();
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
