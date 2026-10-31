<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceRemovalRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Processes\ProcessRuntimeChildAdmission;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
    $this->node = Node::query()->create(['name' => 'runtime-removal', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->workerNode = Node::query()->create(['name' => 'runtime-worker', 'platform' => 'linux', 'status' => 'active', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit']);
    config(['dependency_detection.services_node_id' => $this->workerNode->id]);
    $project = Project::query()->create(['name' => 'Removal', 'slug' => 'removal', 'type' => 'monorepo',
        'repository_url' => 'https://example.test/removal.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'status' => 'active', 'source_layout' => 'checkout', 'branch' => 'proof', 'starting_commit' => str_repeat('a', 40), 'checkout_path' => '/srv/apps/removal/proof']);
    $removal = InstanceRemoval::query()->create(['id' => 'runtime-proof-removal', 'current_step' => 'source_preparation', 'requested_instance_id' => $this->instance->id, 'requested_name' => 'proof',
        'force' => true, 'status' => 'removing', 'inventory_digest' => str_repeat('a', 64), 'total' => 1]);
    InstanceRemovalMember::query()->create(['instance_removal_id' => $removal->id, 'instance_id' => $this->instance->id,
        'project_id' => $project->id, 'node_id' => $this->node->id, 'position' => 0, 'name' => 'proof',
        'environment' => 'development', 'source_layout' => 'checkout', 'repository_identity' => $project->repository_identity,
        'checkout_path' => $this->instance->checkout_path, 'root' => 'public', 'common_repository_path' => $this->instance->checkout_path,
        'source_identity' => 'runtime-removal:1', 'linked_worktree_paths' => [$this->instance->checkout_path], 'source_digest' => str_repeat('a', 64),
        'branch' => 'proof', 'starting_commit' => str_repeat('a', 40), 'source_commit' => str_repeat('a', 40), 'runtime_published' => false]);
    $this->instance->update(['status' => 'removing']);
    $this->process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id,
        'name' => 'queue', 'runtime' => 'systemd', 'runtime_config' => ['command' => 'php artisan queue:work'],
        'desired_state' => 'running', 'status' => 'active']);
    $this->schedule = Schedule::query()->create(['target_type' => Instance::MorphAlias, 'target_id' => $this->instance->id,
        'host_node_id' => $this->node->id, 'name' => 'scheduler', 'calendar' => 'minutely', 'command' => 'php artisan schedule:run',
        'timeout_seconds' => 60, 'desired_timer_state' => 'enabled', 'status' => 'active']);
    $this->parent = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'running', 'lock_key' => 'instance:'.$this->instance->id,
        'input' => ['instance_id' => $this->instance->id, 'additional_route_ids' => [],
            'members' => [['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'run_teardown' => false, 'route_id' => null]]]]);
    $key = 'removal-'.$this->instance->id.'-runtime-intent';
    $descriptor = app(InstanceLifecycleExecution::class)->descriptor($key, 'gateway', 'instance.lifecycle-state',
        ['action' => 'removal-runtime-intent', 'operation_id' => $this->parent->id, 'instance_id' => $this->instance->id]);
    $this->parent->update(['state' => ['lifecycle_bindings' => [$key => $descriptor], 'executor_runs' => [$key => [...$descriptor,
        'result' => ['process_ids' => [$this->process->id], 'schedule_ids' => [$this->schedule->id]]]]]]);
    $this->parent->steps()->create(['name' => $key, 'position' => 0, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    $this->runtime = app(InstanceRemovalRuntime::class);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
});

it('pins per-record children to the proven inventory after another record appears', function (): void {
    $plan = $this->runtime->children($this->parent, $this->instance->id);
    $other = $this->process->replicate()->fill(['name' => 'later']);
    $other->save();
    expect($this->runtime->children($this->parent, $this->instance->id))->toBe($plan)
        ->and(array_column($plan, 'kind'))->toBe(['process.destroy', 'schedule.destroy'])
        ->and($plan[0]['input'])->toBe(['process_id' => $this->process->id])
        ->and($plan[1]['input'])->toBe(['id' => $this->schedule->id]);
    expect(fn () => $this->runtime->assertRemoved($this->parent, $this->instance->id))
        ->toThrow(ResourceOperationException::class);
});

it('refuses an unrecorded record, changed Node, wrong parent or changed ownership', function (string $change, string $family): void {
    $plan = $this->runtime->children($this->parent, $this->instance->id);
    $intent = $plan[$family === 'process' ? 0 : 1];
    if ($change === 'input') {
        $intent['input'] = $family === 'process' ? ['process_id' => $this->process->id + 100] : ['id' => 'foreign'];
    } elseif ($change === 'node') {
        $intent['node_id'] = $this->workerNode->id;
    } elseif ($change === 'parent') {
        $this->parent->kind = 'instance.create';
    } elseif ($family === 'process') {
        $this->process->update(['owner_type' => Node::class, 'owner_id' => $this->node->id]);
    } else {
        $this->schedule->update(['target_type' => Node::class, 'target_id' => $this->node->id]);
    }
    $admission = app($family === 'process' ? ProcessRuntimeChildAdmission::class : ScheduleRuntimeChildAdmission::class);
    expect(fn () => $admission->admit($this->parent, $intent))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 1);
})->with(['input', 'node', 'parent', 'owner'])->with(['process', 'schedule']);

it('composes owning admissions and requires native runtime projection before database cleanup', function (): void {
    $agent = (new FakeAgentCommandRunner)->answer('process.remove', [])->answer('schedule.remove', []);
    app()->instance(AgentCommandRunner::class, $agent);
    $executor = app(SwampOperationExecutor::class);
    foreach ($this->runtime->children($this->parent, $this->instance->id) as $intent) {
        $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->parent, ['key' => $intent['key']])['operation']);
        expect($child->kind)->toBe($intent['kind'])->and($child->node_id)->toBe($this->node->id);
        $replay = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->parent, ['key' => $intent['key']])['operation']);
        expect($replay->id)->toBe($child->id);
        $child->refresh();
        $writer = $child->kind === 'process.destroy' ? 'process_control_writer' : 'schedule_control_writer';
        expect($child->state[$writer])->toBe('direct');
        expect($child->lock_key)->toBeNull();
        expect(fn () => $executor->claim($child->kind, 'old', $this->workerNode->id, $child->id))->toThrow(ResourceOperationException::class);
        expect(fn () => $this->runtime->assertRemoved($this->parent->refresh(), $this->instance->id))->toThrow(ResourceOperationException::class);
        app(DirectOperation::class)->projection($child)->apply($child, ['run_id' => $child->state['swamp_run_id'],
            'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00Z', 'finished_at' => '2026-10-30T12:00:01Z',
            'result' => ['ready' => true]]);
    }
    $this->runtime->assertRemoved($this->parent->refresh(), $this->instance->id);
    expect(array_column($agent->calls, 'command'))->toBe([]);
    $this->assertModelMissing($this->process);
    $this->assertModelMissing($this->schedule);
    $this->assertModelExists($this->instance);
    $later = $this->process->replicate()->fill(['name' => 'late-worker', 'status' => 'active']);
    $later->save();
    expect(fn () => $this->runtime->assertRemoved($this->parent, $this->instance->id))
        ->toThrow(ResourceOperationException::class);
});

it('uses Schedule UUIDs in the public inventory and proves an empty runtime without extra children', function (): void {
    $key = 'removal-'.$this->instance->id.'-runtime-intent';
    $contract = app(OperationExecutionContract::class);
    expect($contract->result($this->parent, ['process_ids' => [$this->process->id], 'schedule_ids' => [$this->schedule->id]]))
        ->toBe(['process_ids' => [$this->process->id], 'schedule_ids' => [$this->schedule->id]]);
    $state = $this->parent->state;
    $state['executor_runs'][$key]['result'] = ['process_ids' => [], 'schedule_ids' => []];
    $this->parent->update(['state' => $state]);
    $this->process->delete();
    $this->schedule->delete();
    expect($this->runtime->children($this->parent, $this->instance->id))->toBe([]);
    $this->runtime->assertRemoved($this->parent, $this->instance->id);
    $this->assertDatabaseCount('operations', 1);
});
