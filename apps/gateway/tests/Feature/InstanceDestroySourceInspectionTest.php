<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceDestroySourceInspection;
use App\Domain\Instances\InstanceDestroyWorkflowInput;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Native capture', 'slug' => 'native-capture',
        'repository_url' => 'https://github.com/example/capture.git', 'root' => 'public']);
    $node = Node::query()->create(['name' => 'capture-dev', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.29', 'public_ssh_host' => '192.0.2.29']);
    orbit_test_set_app_placement_role($node, false);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'proof', 'source_layout' => 'checkout', 'checkout_path' => '/srv/apps/capture/proof',
        'status' => 'reserved', 'source_prepare_id' => (string) Str::uuid()]);
    $this->sources = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $this->sources->shouldNotReceive('inspect');
    app()->instance(DevelopmentInstanceSourceRemoval::class, $this->sources);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);
});

it('records only stored source identity before native submission without a remote source or account read', function (): void {
    $input = app(StartInstanceDestroyAction::class)->snapshot($this->instance, true);

    expect($input['source_pending'])->toBeTrue()
        ->and($input['members'][0]['checkout_path'])->toBe($this->instance->checkout_path)
        ->and($input['members'][0]['user'])->toBe('orbit')
        ->and($input['members'][0]['group'])->toBeNull()
        ->and($input['members'][0]['origin'])->toBeNull()
        ->and($input['members'][0]['worktree_inventory'])->toBeNull()
        ->and($input['members'][0]['source_identity'])->toBe('pending')
        ->and($this->instance->refresh()->status->value)->toBe('reserved');
});

it('captures native facts once and retries failed removal with the exact captured identity', function (): void {
    $input = app(DirectInstanceDestroyIntent::class)->record(app(StartInstanceDestroyAction::class)->snapshot($this->instance, true));
    $operation = new Operation(['kind' => 'instance.destroy', 'node_id' => $this->instance->node_id, 'input' => $input]);
    $helper = app(InstanceDestroySourceInspection::class);
    expect($helper->executable($input)['source_pending'])->toBeTrue();
    $capture = nativeDestroySourceCapture($this->instance);
    $captured = $helper->capture($operation, $capture);
    $record = InstanceRemoval::query()->findOrFail($input['removal_id'])->members()->sole();

    expect($captured['source_pending'])->toBeFalse()
        ->and($captured['direct_destroy']['members'][0]['source']['group'])->toBe('apps')
        ->and($captured['direct_destroy']['members'][0]['source']['source_identity'])->toBe('42:73')
        ->and($record->source_identity)->toBe('42:73')
        ->and($record->origin)->toBe($capture['members'][0]['origin'])
        ->and($record->worktree_inventory)->toBe($capture['members'][0]['worktree_inventory'])
        ->and($record->removal->native_sources)->toBe($capture);
    $record->removal->update(['status' => 'failed', 'failed_step' => 'source_preparation', 'error_code' => 'instance.test_failure']);
    $retry = app(StartInstanceDestroyAction::class)->snapshot($this->instance->refresh(), true);
    expect($helper->executable($retry))->toEqual($captured);
    $different = $capture;
    $different['members'][0]['source_identity'] = '999:999';
    expect($helper->capture($operation, $different))->toEqual($captured)
        ->and($record->refresh()->source_identity)->toBe('42:73');
});

it('persists additive native metadata without replacing original facts for the next failed-removal retry', function (): void {
    $input = app(DirectInstanceDestroyIntent::class)->record(app(StartInstanceDestroyAction::class)->snapshot($this->instance, true));
    $operation = new Operation(['kind' => 'instance.destroy', 'input' => $input]);
    $helper = app(InstanceDestroySourceInspection::class);
    $original = nativeDestroySourceCapture($this->instance);
    $helper->capture($operation, $original);
    $expanded = $original;
    $expanded['members'][0] += ['source' => $this->instance->checkout_path, 'release' => $this->instance->checkout_path,
        'uid' => 1001, 'gid' => 1001, 'git_admin' => $this->instance->checkout_path.'/.git'];
    $helper->capture($operation, $expanded);
    $removal = InstanceRemoval::query()->findOrFail($input['removal_id']);
    $removal->update(['status' => 'failed', 'failed_step' => 'source_preparation', 'error_code' => 'instance.test_failure']);

    $retry = app(StartInstanceDestroyAction::class)->snapshot($this->instance->refresh(), true);
    $native = app(InstanceDestroyWorkflowInput::class)->forOperation(new Operation([
        'kind' => 'instance.destroy', 'input' => $retry, 'node_id' => $this->instance->node_id]));

    expect($native['intent']['captured_sources'])->toBe($expanded)
        ->and($removal->members()->sole()->origin)->toBe($original['members'][0]['origin'])
        ->and($removal->members()->sole()->worktree_inventory)->toBe($original['members'][0]['worktree_inventory']);
    $replacement = $expanded;
    $replacement['members'][0]['origin'] = 'https://github.com/other/replacement.git';
    expect(fn () => $removal->update(['native_sources' => $replacement]))->toThrow(QueryException::class);
});

it('rolls back partial member captures when native member IDs do not match the admitted set', function (): void {
    $input = app(DirectInstanceDestroyIntent::class)->record(app(StartInstanceDestroyAction::class)->snapshot($this->instance, true));
    $operation = new Operation(['kind' => 'instance.destroy', 'input' => $input]);
    $capture = nativeDestroySourceCapture($this->instance);
    $capture['members'][0]['instance_id']++;

    expect(fn () => app(InstanceDestroySourceInspection::class)->capture($operation, $capture))
        ->toThrow(ResourceOperationException::class);
    $removal = InstanceRemoval::query()->findOrFail($input['removal_id']);
    expect($removal->native_sources)->toBeNull()
        ->and($removal->members()->sole()->source_identity)->toBe('pending');
});

it('keeps captured sources and native source artifact immutable in SQLite', function (string $field): void {
    $input = app(DirectInstanceDestroyIntent::class)->record(app(StartInstanceDestroyAction::class)->snapshot($this->instance, true));
    $operation = new Operation(['kind' => 'instance.destroy', 'input' => $input]);
    app(InstanceDestroySourceInspection::class)->capture($operation, nativeDestroySourceCapture($this->instance));
    $removal = InstanceRemoval::query()->findOrFail($input['removal_id']);
    expect(fn () => $field === 'native_sources'
        ? $removal->update(['native_sources' => ['members' => []]])
        : $removal->members()->sole()->update([$field => $field === 'source_digest' ? str_repeat('f', 64) : 'changed']))
        ->toThrow(QueryException::class);
})->with(['origin', 'worktree_inventory', 'source_commit', 'source_digest', 'source_identity', 'common_repository_path', 'native_sources']);

it('keeps legacy source facts immutable when no native pending-source declaration exists', function (): void {
    $removal = InstanceRemoval::query()->create(['id' => (string) Str::uuid(),
        'requested_instance_id' => $this->instance->id, 'requested_name' => $this->instance->name,
        'force' => true, 'inventory_digest' => str_repeat('a', 64), 'total' => 1,
        'status' => 'removing', 'current_step' => 'source_preparation']);
    $member = $removal->members()->create(['instance_id' => $this->instance->id,
        'position' => 0, 'project_id' => $this->instance->project_id, 'node_id' => $this->instance->node_id,
        'route_id' => null, 'name' => $this->instance->name, 'environment' => 'development',
        'source_layout' => 'checkout', 'repository_identity' => $this->instance->project->repository_identity,
        'checkout_path' => $this->instance->checkout_path, 'root' => 'public', 'branch' => null,
        'starting_commit' => null, 'source_commit' => '', 'common_repository_path' => $this->instance->checkout_path,
        'source_identity' => '42:73', 'linked_worktree_paths' => [$this->instance->checkout_path],
        'source_digest' => str_repeat('b', 64), 'runtime_published' => false]);

    expect($removal->native_intent)->toBeNull()->and($member->origin)->toBeNull()
        ->and($member->worktree_inventory)->toBeNull();
    expect(fn () => $member->update(['source_identity' => '99:99']))->toThrow(QueryException::class);
});

/** @return array<string, mixed> */
function nativeDestroySourceCapture(Instance $instance): array
{
    return ['members' => [['instance_id' => $instance->id, 'user' => 'orbit', 'group' => 'apps',
        'source_root' => '/srv/apps', 'source_digest' => str_repeat('c', 64), 'source_commit' => str_repeat('a', 40),
        'common_repository_path' => $instance->checkout_path, 'source_identity' => '42:73',
        'linked_worktree_paths' => [$instance->checkout_path],
        'worktree_inventory' => base64_encode('worktree '.$instance->checkout_path."\0\0"),
        'origin' => 'https://github.com/example/capture.git']], 'inventory_digest' => str_repeat('b', 64)];
}
