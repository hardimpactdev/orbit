<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceDestroyDisposition;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Disposition proof', 'slug' => 'disposition-proof',
        'repository_url' => 'https://github.com/example/disposition.git', 'root' => 'public']);
    $this->node = Node::query()->create(['name' => 'disposition-dev', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.39', 'public_ssh_host' => '192.0.2.39']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'removed', 'source_layout' => 'checkout', 'checkout_path' => '/srv/apps/disposition-proof/removed',
        'status' => 'reserved', 'source_prepare_id' => (string) Str::uuid()]);
    $cluster = Cluster::query()->create(['name' => 'disposition-pool', 'state' => 'active']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $neighborNode = Node::query()->create(['name' => 'disposition-prod', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.40', 'public_ssh_host' => '192.0.2.40', 'cluster_id' => $cluster->id]);
    orbit_test_set_app_placement_role($neighborNode, true);
    $this->neighbor = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $neighborNode->id,
        'name' => 'neighbor', 'source_layout' => 'checkout', 'checkout_path' => '/srv/apps/disposition-proof/neighbor',
        'status' => 'reserved']);
    $this->route = Route::query()->create(['project_id' => $this->project->id, 'cluster_id' => $cluster->id,
        'domain' => 'disposition.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->target = $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->neighborTarget = $this->route->targets()->create(['instance_id' => $this->neighbor->id, 'position' => 1]);
    $this->disposition = app(InstanceDestroyDisposition::class);
});

it('records parent-owned removal without admitting a child or changing shared Route intent', function (): void {
    $saved = $this->disposition->prepare($this->instance);
    $removal = InstanceRemoval::query()->findOrFail($saved['removal_id']);

    expect($saved['parent_owns_routes'])->toBeTrue()
        ->and($saved['direct_destroy']['routes'])->toBe([])
        ->and($saved['source_pending'])->toBeTrue()
        ->and($removal->members()->sole()->route_ids)->toBe([$this->route->id])
        ->and($this->instance->refresh()->status->value)->toBe('removing')
        ->and($this->route->refresh()->status->value)->toBe('pending')
        ->and($this->route->targets()->count())->toBe(2)
        ->and(Operation::query()->count())->toBe(0);
});

it('keeps target detachment and parent projection guarded until source preparation is recorded', function (): void {
    $saved = $this->disposition->prepare($this->instance);

    expect(fn () => $this->target->delete())->toThrow(QueryException::class);
    expect(fn () => $this->disposition->project($saved))->toThrow(ResourceOperationException::class);
    expect(fn () => $this->disposition->sourcePrepared($saved))->toThrow(ResourceOperationException::class);

    $this->assertModelExists($this->instance);
    $this->assertModelExists($this->target);
    $this->assertModelExists($this->neighborTarget);
    expect(InstanceRemoval::query()->findOrFail($saved['removal_id'])->members()->sole()->source_prepared_at)->toBeNull();
});

it('captures native facts and projects one removed member while preserving its shared Route and neighbor', function (): void {
    $saved = $this->disposition->prepare($this->instance);
    $inspection = dispositionNativeCapture($this->instance);
    $captured = $this->disposition->capture($saved, $inspection);
    $different = $inspection;
    $different['members'][0]['source_identity'] = '999:999';
    expect($this->disposition->capture($saved, $different))->toEqual($captured);
    expect($captured['source_pending'])->toBeFalse()
        ->and($captured['direct_destroy']['members'][0]['source']['source_identity'])->toBe('42:73');

    $this->disposition->sourcePrepared($saved);
    $member = InstanceRemoval::query()->findOrFail($saved['removal_id'])->members()->sole();
    $preparedAt = $member->source_prepared_at;
    $this->disposition->sourcePrepared($saved);
    expect($member->refresh()->source_prepared_at)->toEqual($preparedAt);
    expect(fn () => $this->disposition->project($saved))->toThrow(ResourceOperationException::class);
    $this->target->delete();
    $completed = $this->disposition->project($saved);

    expect($completed->status->value)->toBe('completed')
        ->and($completed->members->sole()->source_identity)->toBe('42:73')
        ->and($completed->members->sole()->row_deleted_at)->not->toBeNull()
        ->and($this->disposition->project($saved)->id)->toBe($completed->id)
        ->and($this->route->refresh()->targets()->pluck('instance_id')->all())->toBe([$this->neighbor->id]);
    $this->assertModelMissing($this->instance);
    $this->assertModelExists($this->route);
    $this->assertModelExists($this->neighbor);
    $this->assertModelExists($this->neighborTarget);
    expect(Operation::query()->count())->toBe(0);
});

/** @return array<string, mixed> */
function dispositionNativeCapture(Instance $instance): array
{
    return ['members' => [['instance_id' => $instance->id, 'user' => 'orbit', 'group' => 'apps',
        'source_root' => '/srv/apps', 'source_digest' => str_repeat('c', 64), 'source_commit' => str_repeat('a', 40),
        'common_repository_path' => $instance->checkout_path, 'source_identity' => '42:73',
        'linked_worktree_paths' => [$instance->checkout_path],
        'worktree_inventory' => base64_encode('worktree '.$instance->checkout_path."\0\0"),
        'origin' => 'https://github.com/example/disposition.git']], 'inventory_digest' => str_repeat('b', 64)];
}
