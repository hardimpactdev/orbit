<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('records the actual removal failure and keeps the assignment retryable', function (string $step, string $expected, string $code): void {
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'remove', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.22', 'wireguard_ip' => '10.44.0.22', 'user' => 'orbit',
    ]));
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'removing']);
    $operation = Operation::query()->create(['kind' => 'node.role_remove', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'lock_key' => 'node:'.$node->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 1,
        'input' => ['_role_remove' => ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => 'database']],
        'state' => ['swamp_run_id' => 'fixture-removal']]);

    app(NodeRoleRemovalProjection::class)->apply($operation, ['run_id' => 'fixture-removal', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [$step => ['status' => 'failed'], 'Remove the run folder' => ['status' => 'failed']],
            'summary' => ['failed_steps' => [$step, 'Remove the run folder']]]]);

    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($assignment->failed_step)->toBe('remove:'.$expected)
        ->and($assignment->error_code)->toBe($code)
        ->and($operation->refresh()->lock_key)->toBeNull()
        ->and($node->refresh()->status)->toBe(LifecycleStatus::Active);
    Http::assertNothingSent();
})->with([
    ['Reach the Node', 'Reach the Node', 'node.removal_incomplete'],
    ['Require drained CI', 'Require drained CI', 'node.removal_incomplete'],
    ['Keep public SSH access', 'firewall-recovery', 'node.firewall_recovery_failed'],
    ['Remove the role', 'Remove the role', 'node.removal_incomplete'],
]);

it('deletes only the successful assignment and retains the Node and its other roles', function (): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'remove', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.22', 'wireguard_ip' => '10.44.0.22', 'user' => 'orbit',
    ]));
    $kept = $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'removing']);
    $operation = Operation::query()->create(['kind' => 'node.role_remove', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'lock_key' => 'node:'.$node->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 1,
        'input' => ['_role_remove' => ['node_id' => $node->id, 'assignment_id' => $assignment->id, 'role' => 'database']],
        'state' => ['swamp_run_id' => 'fixture-removal']]);
    app(NodeRoleRemovalProjection::class)->apply($operation, ['run_id' => 'fixture-removal', 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Collect the removal result' => ['status' => 'succeeded', 'stdout' => '{"reachable":false}'],
            'Remove the run folder' => ['status' => 'succeeded']], 'summary' => ['failed_steps' => []]]]);
    expect($assignment->fresh())->toBeNull()->and($kept->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($node->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($operation->refresh()->result['offline'])->toBeTrue()->and($operation->lock_key)->toBeNull();
});
