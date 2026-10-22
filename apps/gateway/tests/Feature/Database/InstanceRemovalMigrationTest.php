<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('rejects a removal operation without its initial step', function (): void {
    [$instance] = orb179_removal_fixture('missing-step');

    expect(fn () => DB::table('instance_removals')->insert([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => 'removing',
        'current_step' => null,
        'failed_step' => null,
        'error_code' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))
        ->toThrow(QueryException::class);
});

it('records immutable requested identity, force choice, and ordered member inventory', function (): void {
    [$first, $firstRoute] = orb179_removal_fixture('first');
    [$second, $secondRoute] = orb179_removal_fixture('second');
    $removal = orb179_removal_operation($first, total: 2, force: true);
    $secondMember = $removal->members()->create(orb179_removal_member($second, $secondRoute, 1));

    expect(fn () => $second->update(['status' => InstanceState::Removing]))
        ->toThrow(QueryException::class);

    $second->refresh();
    $firstMember = $removal->members()->create(orb179_removal_member($first, $firstRoute, 0));
    $duplicate = orb179_removal_operation($first);

    expect($removal->refresh()->force)
        ->toBeTrue()
        ->and($removal->status)
        ->toBe(InstanceRemovalStatus::Removing)
        ->and($removal->current_step)
        ->toBe(InstanceRemovalStep::SourcePreparation)
        ->and($removal->members->pluck('id')->all())
        ->toBe([$firstMember->id, $secondMember->id])
        ->and($first->refresh()->removalMember?->id)
        ->toBe($firstMember->id)
        ->and($firstMember->removal->id)
        ->toBe($removal->id)
        ->and(fn () => $removal->update(['requested_name' => 'changed']))
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->update(['force' => false]))
        ->toThrow(QueryException::class)
        ->and(fn () => $firstMember->update(['position' => 1]))
        ->toThrow(QueryException::class)
        ->and(fn () => $firstMember->update(['source_identity' => '1:101']))
        ->toThrow(QueryException::class)
        ->and(fn () => $firstMember->update(['source_commit' => str_repeat('f', 40)]))
        ->toThrow(QueryException::class)
        ->and(fn () => $duplicate->members()->create(orb179_removal_member($first, $firstRoute, 0)))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('instance_removals')
            ->where('id', $removal->id)
            ->update([
                'status' => 'queued',
            ]))
        ->toThrow(QueryException::class);

    $removal
        ->refresh()
        ->update([
            'status' => InstanceRemovalStatus::Failed,
            'current_step' => InstanceRemovalStep::SourcePreparation,
            'failed_step' => InstanceRemovalStep::SourcePreparation,
            'error_code' => 'instance.source_preparation_failed',
        ]);

    expect($removal->refresh()->status)
        ->toBe(InstanceRemovalStatus::Failed)
        ->and(fn () => $removal->update(['status' => InstanceRemovalStatus::Removing]))
        ->toThrow(QueryException::class);

    $removal
        ->refresh()
        ->update([
            'status' => InstanceRemovalStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);
    $first->update(['status' => InstanceState::Removing]);
    $second->update(['status' => InstanceState::Removing]);

    expect($first->refresh()->status)
        ->toBe(InstanceState::Removing)
        ->and($second->refresh()->status)
        ->toBe(InstanceState::Removing);
});

it('orders member checkpoints and completes only after every row deletion', function (): void {
    [$instance, $route] = orb179_removal_fixture('checkpoints');
    $removal = orb179_removal_operation($instance);
    $member = $removal->members()->create(orb179_removal_member($instance, $route, 0));
    $instance->update(['status' => InstanceState::Removing]);

    expect(fn () => $member->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']))
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->update([
            'status' => InstanceRemovalStatus::Completed,
            'current_step' => null,
        ]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $removal->refresh();
    $member->update(['source_prepared_at' => now()]);
    $member->refresh();

    expect(fn () => $member->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => str_repeat('c', 64),
    ]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $route->targets()->delete();
    $member->update(['route_cleared_at' => now(), 'route_outcome' => 'retained']);

    expect(fn () => $member->update(['runtime_cleaned_at' => now()]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $member->update([
        'source_finalized_at' => now(),
        'finalization_receipt' => str_repeat('c', 64),
    ]);
    $member->update(['runtime_cleaned_at' => now()]);

    expect(fn () => $member->update(['row_deleted_at' => now()]))
        ->toThrow(QueryException::class);

    $member->refresh();
    $instance->delete();
    $member->update(['row_deleted_at' => now()]);
    $removal->update([
        'status' => InstanceRemovalStatus::Completed,
        'current_step' => null,
    ]);

    expect($removal->refresh()->status)
        ->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->current_step)
        ->toBeNull()
        ->and($member->refresh()->row_deleted_at)
        ->not
        ->toBeNull()
        ->and(fn () => $member->update(['runtime_cleaned_at' => now()->addSecond()]))
        ->toThrow(QueryException::class)
        ->and(fn () => $member->delete())
        ->toThrow(QueryException::class)
        ->and(fn () => $removal->delete())
        ->toThrow(QueryException::class);
});

it('rejects a null branch in removal evidence for a resolved starting commit', function (): void {
    [$instance, $route] = orb179_removal_fixture('null-branch');
    $operation = orb179_removal_operation($instance);
    $member = orb179_removal_member($instance, $route, 0);

    expect(fn () => $operation->members()->create([...$member, 'branch' => null]))
        ->toThrow(QueryException::class, 'Invalid Instance removal member contract.')
        ->and($operation->members()->count())->toBe(0);

    $item = $operation->members()->create($member);

    expect($item->refresh()->branch)->toBe('main')
        ->and($item->starting_commit)->toBe(str_repeat('a', 40));
});

it('persists source evidence without coupling it to the starting commit', function (): void {
    [$instance, $route] = orb179_removal_fixture('source-evidence');
    $operation = orb179_removal_operation($instance);
    $item = $operation->members()->create([
        ...orb179_removal_member($instance, $route, 0),
        'source_commit' => str_repeat('b', 40),
    ]);

    expect($item->refresh()->source_commit)->toBe(str_repeat('b', 40))
        ->and($item->starting_commit)->toBe(str_repeat('a', 40));
});

/** @return array{Instance, Route} */
function orb179_removal_fixture(
    string $suffix,
    ?Project $project = null,
    ?Node $node = null,
    ?string $timestamp = null,
    string $environment = 'development',
): array {
    $project ??= Project::query()->create([
        'name' => "Removal {$suffix}",
        'slug' => "removal-{$suffix}",
        'repository_url' => "https://example.test/acme/{$suffix}.git",
        'repository_identity' => "example.test/acme/{$suffix}",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node ??= Node::query()->create([
        'name' => "removal-{$suffix}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$suffix}.example.test",
        'wireguard_ip' => '10.44.'.((mb_strlen($suffix) % 200) + 1).'.10',
        'tld' => null,
    ]);
    $node->roles()->firstOrCreate(
        ['role' => $environment === 'production' ? RoleName::AppProd : RoleName::AppDev],
        ['status' => LifecycleStatus::Active],
    );
    $attributes = [
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $suffix,
        'source_layout' => 'checkout',
        'checkout_path' => "/srv/orbit/apps/{$project->slug}/{$suffix}",
        'root' => null,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::SourceResolved,
    ];

    if ($timestamp !== null) {
        $attributes['created_at'] = $timestamp;
        $attributes['updated_at'] = $timestamp;
    }

    $instance = Instance::query()->create($attributes);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'domain' => "{$suffix}.{$project->slug}.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        ...($timestamp === null ? [] : ['created_at' => $timestamp, 'updated_at' => $timestamp]),
    ]);
    $route
        ->targets()
        ->create([
            'instance_id' => $instance->id,
            'position' => 0,
            ...($timestamp === null ? [] : ['created_at' => $timestamp, 'updated_at' => $timestamp]),
        ]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);

    return [$instance->load('project'), $route];
}

function orb179_removal_operation(
    Instance $instance,
    int $total = 1,
    bool $force = false,
): InstanceRemoval {
    return InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => str_repeat('d', 64),
        'total' => $total,
        'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
}

/** @return array<string, mixed> */
function orb179_removal_member(Instance $instance, Route $route, int $position): array
{
    return [
        'position' => $position,
        'instance_id' => $instance->id,
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'route_id' => $route->id,
        'name' => $instance->name,
        'environment' => $instance->defaultAppEnv(),
        'source_layout' => $instance->source_layout,
        'repository_identity' => $instance->project->repository_identity,
        'checkout_path' => $instance->checkout_path,
        'root' => $instance->effectiveRoot(),
        'branch' => $instance->branch,
        'starting_commit' => $instance->starting_commit,
        'source_commit' => $instance->starting_commit,
        'common_repository_path' => $instance->checkout_path,
        'source_identity' => "1:{$instance->id}",
        'linked_worktree_paths' => [$instance->checkout_path],
        'source_digest' => hash('sha256', "source:{$instance->id}"),
    ];
}
