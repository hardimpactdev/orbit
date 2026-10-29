<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceCloneAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceLifecycleCommand;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Schedules\ScheduleInstanceOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\WebSocketAppTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(),
        ...require app_path('Domain/Instances/lifecycle-operation-kinds.php'),
    ]));
    $project = Project::query()->create(['name' => 'Clone admission', 'slug' => 'clone-admission', 'type' => 'monorepo',
        'repository_url' => 'https://github.com/example/clone.git', 'root' => '.', 'default_branch' => 'main']);
    $node = static fn (string $name): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => $name === 'dev' ? '10.44.0.9' : '10.44.0.10']);
    $this->dev = $node('dev');
    $this->prod = $node('prod');
    NodeRole::query()->create(['node_id' => $this->dev->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    NodeRole::query()->create(['node_id' => $this->prod->id, 'role' => RoleName::AppProd, 'status' => 'active']);
    $this->candidate = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->dev->id, 'name' => 'default',
        'status' => 'active', 'provisioning_step' => 'active', 'checkout_path' => '/srv/apps/clone/default', 'source_layout' => 'checkout',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $this->data = new CloneInstanceData(nodeId: $this->prod->id, name: 'preview', previewName: 'preview', branch: null, sqliteSourcePath: null);
    $this->inspector = new class($this->dev) implements InstanceCloneCandidateInspector
    {
        public string $commit = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        public int $calls = 0;

        public function __construct(private Node $node) {}

        public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource
        {
            $this->calls++;

            return new CloneCandidateSource($candidate->id, 'development', $candidate->checkout_path, 'orbit', 'main', $this->commit, $this->node);
        }
    };
    app()->instance(InstanceCloneCandidateInspector::class, $this->inspector);
    Queue::fake();
});

it('reserves clone intent without provisioning or PHP dispatch', function (): void {
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $target = Instance::query()->findOrFail($operation->target_id);
    expect($operation->kind)->toBe('instance.clone')->and($operation->node_id)->toBe($this->prod->id)
        ->and($target->provisioning_step)->toBe('clone-reserved')
        ->and($operation->input['candidate_node_id'])->toBe($this->dev->id)
        ->and($operation->input['candidate_commit'])->toBe($this->inspector->commit)
        ->and($operation->input['route_id'])->toBeNull();
    Queue::assertNothingPushed();
});

it('records a fresh observed candidate on retry without rewriting the original clone provenance', function (): void {
    $first = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $first->update(['status' => 'failed', 'lock_key' => null]);
    Instance::query()->whereKey($first->target_id)->update(['provisioning_step' => 'clone-dns-published', 'source_is_laravel' => false]);
    $this->inspector->commit = str_repeat('c', 40);
    $retry = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    expect($retry->target_id)->toBe($first->target_id)
        ->and($retry->input['candidate_commit'])->toBe(str_repeat('c', 40))
        ->and($retry->input['initial_provisioning_step'])->toBe('clone-dns-published')
        ->and(Instance::query()->findOrFail($first->target_id)->clone_candidate_commit)->toBe(str_repeat('a', 40));
});

it('does not inspect the candidate again for a completed identical clone', function (): void {
    $first = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $first->update(['status' => 'succeeded', 'lock_key' => null]);
    Instance::query()->whereKey($first->target_id)->update(['status' => 'active', 'provisioning_step' => 'active', 'clone_completed_at' => now()]);
    $retry = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    expect($retry->input['initial_completed'])->toBeTrue()->and($this->inspector->calls)->toBe(1);
});

it('refuses changed immutable clone input before admitting a second target', function (): void {
    app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $changed = new CloneInstanceData(nodeId: $this->prod->id, name: 'preview', previewName: 'changed', branch: null, sqliteSourcePath: null);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($this->candidate, $changed))->toThrow(ResourceOperationException::class);
    expect(Instance::query()->where('clone_candidate_id', $this->candidate->id)->count())->toBe(1);
});

it('copies only stored clone environment values and preserves later edits on retry', function (): void {
    $this->candidate->update(['source_is_laravel' => false]);
    $app = new WebSocketAppIssuer()->issue('clone-source');
    WebSocketAppTarget::query()->create(['websocket_app_id' => $app->id, 'instance_id' => $this->candidate->id]);
    foreach ([...new WebSocketAppEnvProjection()->project($app), 'APP_NAME' => 'Clone source', 'DB_PASSWORD' => 'synthetic-db-secret'] as $key => $value) {
        $this->candidate->environmentValues()->create(['env_key' => $key, 'env_value' => $value]);
    }
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $target = Instance::query()->findOrFail($operation->target_id);
    $target->update(['status' => InstanceState::SourceResolved, 'provisioning_step' => 'clone-caddy-access-prepared', 'source_is_laravel' => false]);
    $command = app(InstanceLifecycleCommand::class);
    expect($command->run($operation, ['action' => 'clone-environment', 'operation_id' => $operation->id]))->toBe(['environment_copied' => true]);
    expect($target->environmentValues()->pluck('env_key')->all())->toEqualCanonicalizing(['APP_NAME', 'APP_ENV', 'APP_DEBUG']);
    expect($target->environmentValues()->where('env_key', 'APP_ENV')->sole()->env_value)->toBe('production');
    expect(WebSocketAppTarget::query()->pluck('instance_id')->all())->toBe([$this->candidate->id]);

    $target->environmentValues()->where('env_key', 'APP_NAME')->sole()->update(['env_value' => 'Retained edit']);
    $command->run($operation, ['action' => 'clone-environment', 'operation_id' => $operation->id]);
    expect($target->environmentValues()->where('env_key', 'APP_NAME')->sole()->env_value)->toBe('Retained edit');
    Queue::assertNothingPushed();
});

it('admits the shipped definition children with stopped Process copies and disabled Schedule UUIDs', function (): void {
    $project = $this->candidate->project;
    $project->processDefinitions()->create(['name' => 'queue', 'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work']]]);
    $project->scheduleDefinitions()->create(['name' => 'scheduler', 'environments' => ['production'],
        'spec' => ['calendar' => 'minutely', 'command' => '/usr/bin/php artisan schedule:run', 'timeout_seconds' => 60]]);
    config(['dependency_detection.services_node_id' => $this->dev->id]);
    $parent = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $parent->update(['status' => 'running']);
    $target = Instance::query()->findOrFail($parent->target_id);
    $target->update(['status' => InstanceState::SourceResolved, 'source_is_laravel' => false]);
    $result = app(InstanceLifecycleCommand::class)->run($parent, ['action' => 'clone-definitions', 'operation_id' => $parent->id]);
    $descriptor = app(InstanceLifecycleExecution::class)->descriptor('clone-definitions', 'gateway', 'instance.lifecycle-state',
        ['action' => 'clone-definitions', 'operation_id' => $parent->id]);
    $parent->update(['state' => ['lifecycle_bindings' => ['clone-definitions' => $descriptor],
        'executor_runs' => ['clone-definitions' => [...$descriptor, 'result' => $result]]]]);
    $parent->steps()->create(['name' => 'clone-definitions', 'position' => 0, 'sequence' => 1, 'status' => 'succeeded', 'resumable' => true]);
    $intent = ['key' => 'clone-environment-sync', 'kind' => 'instance.sync_environment', 'node_id' => $parent->node_id, 'input' => ['instance_id' => $target->id]];
    $stamp = ['intent' => $intent, 'admitted' => ['parent_operation_id' => $parent->id, 'key' => $intent['key'], 'kind' => $intent['kind'],
        'node_id' => $parent->node_id, 'target_type' => 'instance', 'target_id' => $target->id, 'input' => $intent['input'], 'run_constraints' => []]];
    $environment = Operation::query()->create(['kind' => $intent['kind'], 'node_id' => $parent->node_id, 'target_type' => 'instance',
        'target_id' => $target->id, 'input' => $intent['input'], 'status' => 'succeeded']);
    $environment->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $intent['key'], 'child_admission' => $stamp,
        'child_fingerprint' => OperationChildIntent::fingerprint($stamp)])->save();
    foreach (['clone-processes' => ProcessInstanceOperation::class, 'clone-schedules' => ScheduleInstanceOperation::class] as $key => $execution) {
        $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($parent, ['key' => $key])['operation']);
        expect($child->input)->toBe(['instance_id' => $target->id])->and($child->node_id)->toBe($this->prod->id)
            ->and($child->lock_key)->toBeNull();
        $plan = app($execution)->childContext($child)['children'];
        expect($plan)->toHaveCount(1);
        expect($plan[0]['kind'])->toBe($key === 'clone-processes' ? 'process.create' : 'schedule.create');
    }
    $process = Process::query()->where('owner_id', $target->id)->sole();
    $schedule = Schedule::query()->where('target_id', $target->id)->sole();
    expect($result['process_ids'])->toBe([$process->id])->and($result['schedule_ids'])->toBe([$schedule->id])
        ->and($process->desired_state->value)->toBe('stopped')->and($schedule->desired_timer_state->value)->toBe('disabled');
    Queue::assertNothingPushed();
});
