<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

describe('reserved worktree recovery eligibility', function (): void {
    it('does not treat an active worktree as failed-create cleanup', function (): void {
        $instance = reserved_recovery_instance();
        $instance->project->update(['type' => 'monorepo']);
        $instance->update(['status' => InstanceState::Active]);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeFalse();
    });

    it('refuses recovery eligibility for reservations without creation evidence', function (array $attributes): void {
        $instance = reserved_recovery_instance();
        $instance->update($attributes);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeFalse();
    })->with([
        'already resolved commit' => [['starting_commit' => str_repeat('a', 40)]],
        'no preparation identity' => [['source_prepare_id' => null, 'task_workspace_routed' => null]],
        'registered source' => [['registration_request_id' => 'registered-source']],
        'not a task workspace' => [['task_workspace_routed' => null]],
    ]);

    it('leaves a resolved unrouted worktree on its healthy teardown path', function (): void {
        $instance = reserved_recovery_instance();
        $instance->update(['status' => InstanceState::SourceResolved, 'starting_commit' => str_repeat('a', 40)]);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeFalse();
    });
});

it('recognizes only receipt-bound unresolved seeded worktrees as interrupted creation', function (array $change, bool $expected): void {
    $instance = reserved_recovery_instance();
    $instance->fill(['status' => InstanceState::CheckoutPrepared, 'task_workspace_routed' => null,
        'seed_repository' => '/srv/orbit/apps/reserved-removal/default', 'seed_commit' => str_repeat('a', 40), ...$change]);

    expect(InstanceCreationRecovery::isPreActivation($instance))->toBe($expected);
    if ($expected) {
        $instance->status = InstanceState::Removing;
        expect(InstanceCreationRecovery::isPreActivation($instance, removing: true))->toBeTrue();
    }
})->with([
    'recorded prepared worktree' => [[], true],
    'no preparation ID' => [['source_prepare_id' => null], false],
    'no seed repository' => [['seed_repository' => null], false],
    'no seed commit' => [['seed_commit' => null], false],
    'resolved source' => [['starting_commit' => str_repeat('b', 40)], false],
    'registered source' => [['registration_request_id' => 'registration-1'], false],
    'activated source' => [['status' => InstanceState::Active], false],
]);

it('recognizes an unresolved unrouted task worktree without a preparation ID as pre-activation', function (): void {
    $instance = reserved_recovery_instance();
    $instance->update(['source_prepare_id' => null]);

    expect(InstanceCreationRecovery::isPreActivation($instance))->toBeTrue();
});

/** A reserved worktree Instance created as provisioning records it. */
function reserved_recovery_instance(): Instance
{
    $project = Project::query()->create([
        'name' => 'Reserved removal',
        'slug' => 'reserved-removal',
        'type' => 'laravel-app',
        'repository_url' => 'https://example.test/reserved-removal.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'reserved-removal',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.125',
        'wireguard_ip' => '10.44.1.125',
    ]);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $name = 'task-1253';

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'branch_override' => $name,
        'source_layout' => 'worktree',
        'source_prepare_id' => 'reserved-prepare',
        'checkout_path' => '/srv/orbit/apps/reserved-removal/'.$name,
        'task_workspace_routed' => false,
        'status' => InstanceState::Reserved,
        'starting_commit' => null,
        'failed_step' => null,
        'error_code' => null,
    ]);
}
