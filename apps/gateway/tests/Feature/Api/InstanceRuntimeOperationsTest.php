<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Processes\ProcessRuntimeChildAdmission;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\TaskVm;
use Illuminate\Support\Arr;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\TestCase;

final class InstanceRuntimeTestParent implements OperationCompletion, OperationFamilyChildren, OperationFamilyExecution
{
    public function childContext(Operation $parent): array
    {
        return ['intent' => [], 'children' => $parent->state['child_intents']];
    }

    public function context(Operation $operation): array
    {
        return ['descriptors' => []];
    }

    public function input(Operation $operation, array $run): array
    {
        return [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'runtime-node', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']));
    $this->node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Runtime proof', 'slug' => 'runtime-proof', 'repository_url' => 'https://example.test/runtime.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'environment' => 'production',
        'source_layout' => 'checkout', 'checkout_path' => '/home/runtime-proof/releases/prepared', 'production_user' => 'runtime-proof', 'production_home' => '/home/runtime-proof', 'provisioning_step' => 'source-resolved', 'status' => 'source_resolved']);
    $project->processDefinitions()->create(['name' => 'worker', 'environments' => ['production'], 'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/sleep', '300'], 'restart_policy' => 'never']]);
    $project->scheduleDefinitions()->create(['name' => 'timer', 'environments' => ['production'], 'spec' => ['command' => 'printf private-schedule-command', 'calendar' => 'daily', 'timeout_seconds' => 60]]);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->agents = (new FakeAgentCommandRunner)->answer('process.stage-unit', ['staged' => true, 'previous' => false, 'state' => null])
        ->answer('process.activate-unit', ['backup_left' => false])->answer('process.status', ['state' => 'inactive'])->answer('schedule.install', []);
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->executor = Node::query()->create(['name' => 'separate-executor', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.4']);
    $this->executor->accessibleNodes()->attach($this->node);
    config(['dependency_detection.services_node_id' => $this->executor->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->executor->wireguard_ip]);
    $parent = ['executor' => 'swamp', 'completion' => InstanceRuntimeTestParent::class, 'execution' => InstanceRuntimeTestParent::class,
        'runs' => [], 'children' => [['kind' => 'process.install_instance_definitions', 'node_from' => 'operation.node_id'], ['kind' => 'schedule.install_instance_definitions', 'node_from' => 'operation.node_id'], ['kind' => 'process.restart_instance', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['children_succeeded' => ['process.install_instance_definitions', 'schedule.install_instance_definitions']], 'result_fields' => ['ready']];
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'test.runtime_parent' => $parent]));
    $this->root = app(StartOperationAction::class)->execute('test.runtime_parent', 'instance', $this->instance->id, $this->node->id, [], 'test', null);
    $this->root->update(['state' => ['child_intents' => array_map(fn (string $family): array => ['key' => $family, 'kind' => $family.'.install_instance_definitions', 'node_id' => $this->node->id, 'input' => ['instance_id' => $this->instance->id]], ['process', 'schedule'])]]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $this->root->kind, 'worker' => 'root'])->assertOk();
});

it('installs captured Process and Schedule children stopped and proves them before parent readiness', function (): void {
    app()->instance(ProcessRuntimeStatusIndex::class, Mockery::mock(ProcessRuntimeStatusIndex::class)
        ->shouldReceive('remember')->once()->with(Mockery::type(Process::class), 'inactive')->getMock());
    foreach (['process', 'schedule'] as $family) {
        $response = $this->postJson('/api/v1/operation-executor/'.$this->root->id.'/children', ['worker' => 'root', 'key' => $family])->assertAccepted();
        $id = $response->json('data.id');
        $operation = Operation::query()->findOrFail($id);
        expect($operation->input)->toBe(['instance_id' => $this->instance->id]);
        expect($operation->parent_operation_id)->toBe($this->root->id);
        $worker = $family.'-worker';
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => $worker, 'operation_id' => $id])->assertOk();
        $path = '/api/v1/operation-executor/'.$id;
        $ready = ['worker' => $worker, 'key' => 'runtime-ready', 'transport' => 'gateway', 'name' => $family.'.instance_runtime_ready', 'input' => ['instance_id' => $this->instance->id]];
        $this->postJson($path.'/run', $ready)->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
        $child = $this->postJson($path.'/children', ['worker' => $worker, 'key' => 'runtime-0'])->assertAccepted()->json('data.id');
        $leaf = Operation::query()->findOrFail($child);
        expect($leaf->input['desired'])->toBe($family === 'process' ? 'stopped' : 'disabled');
        runtimeExecuteLeaf($this, $leaf);
        $result = $this->postJson($path.'/run', $ready)->assertOk()->json('data');
        $this->postJson($path.'/step', ['worker' => $worker, 'key' => 'ready-done', 'name' => 'runtime-ready', 'status' => 'succeeded', 'result' => $result])->assertOk();
        $this->postJson($path.'/complete', ['worker' => $worker, 'status' => 'succeeded', 'result' => $result])->assertOk();
    }
    expect(Process::query()->sole()->desired_state->value)->toBe('stopped');
    expect(Schedule::query()->sole()->desired_timer_state->value)->toBe('disabled');
    expect(json_encode(Operation::query()->get()->toArray()))->not->toContain('private-schedule-command');
});

it('refuses enabling an unfinished definition copy during stopped installation', function (string $family): void {
    $id = $this->postJson('/api/v1/operation-executor/'.$this->root->id.'/children', ['worker' => 'root', 'key' => $family])->assertAccepted()->json('data.id');
    $worker = $family.'-worker';
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $family.'.install_instance_definitions', 'worker' => $worker, 'operation_id' => $id])->assertOk();
    if ($family === 'process') {
        Process::query()->sole()->update(['desired_state' => 'running']);
    } else {
        Schedule::query()->sole()->update(['desired_timer_state' => 'enabled']);
    }
    $this->postJson('/api/v1/operation-executor/'.$id.'/children', ['worker' => $worker, 'key' => 'runtime-0'])->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
    expect($this->agents->calls)->toBe([]);
})->with(['process', 'schedule']);

it('proves the exact restarted Process ID list through child execution and HTTP completion', function (bool $empty): void {
    if ($empty) {
        config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    }
    $route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id, 'domain' => 'runtime.example.test',
        'status' => 'pending', 'publication' => 'private', 'provenance' => 'explicit']);
    $route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->agents->answer('process.check-release', ['available' => true])->answer('process.control', [])->answer('process.status', ['state' => 'active']);
    $selected = [];
    if (! $empty) {
        foreach (['first' => [], 'opt-out' => ['restart_on_deploy' => false], 'stopped' => ['desired_state' => 'stopped'], 'failed' => ['status' => 'failed'], 'second' => []] as $name => $attributes) {
            $process = Process::query()->create([...['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id, 'name' => $name,
                'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300']], 'restart_policy' => 'never',
                'status' => 'active', 'desired_state' => 'running', 'restart_on_deploy' => true], ...$attributes]);
            if ($attributes === []) {
                $selected[] = $process->id;
            }
        }
    }
    $this->root->update(['state' => [...($this->root->refresh()->state ?? []), 'child_intents' => [['key' => 'restart', 'kind' => 'process.restart_instance', 'node_id' => $this->node->id, 'input' => ['instance_id' => $this->instance->id]]]]]);
    $id = $this->postJson('/api/v1/operation-executor/'.$this->root->id.'/children', ['worker' => 'root', 'key' => 'restart'])->assertAccepted()->json('data.id');
    $path = '/api/v1/operation-executor/'.$id;
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'process.restart_instance', 'worker' => 'restart-worker', 'operation_id' => $id])->assertOk();
    $ready = ['worker' => 'restart-worker', 'key' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'process.instance_runtime_ready', 'input' => ['instance_id' => $this->instance->id]];
    foreach ($selected as $index => $processId) {
        $this->postJson($path.'/run', $ready)->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
        $child = $this->postJson($path.'/children', ['worker' => 'restart-worker', 'key' => 'runtime-'.$index])->assertAccepted()->json('data.id');
        $leaf = Operation::query()->findOrFail($child);
        expect($leaf->target_id)->toBe($processId);
        runtimeExecuteLeaf($this, $leaf);
    }
    $result = $this->postJson($path.'/run', $ready)->assertOk()->assertJsonPath('data.process_ids', $selected)->json('data');
    $this->postJson($path.'/step', ['worker' => 'restart-worker', 'key' => 'ready-done', 'name' => 'runtime-ready', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'restart-worker', 'status' => 'succeeded', 'result' => ['ready' => true, 'process_ids' => [...$selected, 99999]]])
        ->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($path.'/complete', ['worker' => 'restart-worker', 'status' => 'succeeded', 'result' => $result])->assertOk()->assertJsonPath('data.result.process_ids', $selected);
    $controls = array_filter($this->agents->calls, static fn (array $call): bool => $call['command'] === 'process.control');
    expect(array_values(array_map(static fn (array $call): int => $call['input']['process_id'], $controls)))->toBe($selected);
})->with([false, true])->skip(! OperationKinds::supports('list_of'), 'Requires the shared kit C list-result contract.');

/** Execute descriptors through the real HTTP proof boundary with a fake target agent. */
function runtimeExecuteLeaf(TestCase $test, Operation $operation): void
{
    $worker = 'leaf-'.$operation->id;
    $test->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => $worker, 'operation_id' => $operation->id])->assertOk();
    $path = '/api/v1/operation-executor/'.$operation->id;
    $names = match ($operation->kind) {
        'process.create' => ['process-stage-unit', 'process-activate-unit', 'process-status', 'runtime-ready'],
        'process.restart' => ['process-check-release', 'process-restart', 'process-status', 'runtime-ready'],
        default => ['schedule-install'],
    };
    $result = [];
    foreach ($names as $name) {
        $context = app(OperationExecutionContract::class)->context($operation->refresh());
        $descriptor = array_find($context['descriptors'], static fn (array $entry): bool => $entry['key'] === $name);
        expect($descriptor)->not->toBeNull();
        $test->getJson($path.'/receipts/'.$name)->assertOk()->assertJsonPath('data.state', 'unknown');
        $result = $test->postJson($path.'/run', ['worker' => $worker, 'key' => $name, 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'input' => $descriptor['input']])->assertOk()->json('data');
        $test->postJson($path.'/step', ['worker' => $worker, 'key' => $name.'-done', 'name' => $name, 'status' => 'succeeded', 'result' => $result])->assertOk();
    }
    $test->postJson($path.'/complete', ['worker' => $worker, 'status' => 'succeeded', 'result' => $result])->assertOk();
}

it('refuses executor Node runtime composition before recording children or definition copies', function (string $peer, string $kind): void {
    config($peer === 'services'
        ? ['dependency_detection.services_node_id' => $this->node->id, 'operation_executor.worker_node_ids' => [$this->executor->id]]
        : ['operation_executor.worker_node_ids' => [$this->node->id]]);
    $restart = $kind === 'process.restart_instance';
    if ($restart) {
        Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id, 'name' => 'worker',
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '300']], 'restart_policy' => 'never',
            'status' => 'active', 'desired_state' => 'running', 'restart_on_deploy' => true]);
    }
    $this->root->update(['state' => [...($this->root->refresh()->state ?? []), 'child_intents' => [[
        'key' => 'protected', 'kind' => $kind, 'node_id' => $this->node->id, 'input' => ['instance_id' => $this->instance->id],
    ]]]]);

    $this->postJson('/api/v1/operation-executor/'.$this->root->id.'/children', ['worker' => 'root', 'key' => 'protected'])
        ->assertConflict()->assertJsonPath('error.code', str_starts_with($kind, 'process.') ? 'process.executor_dependency' : 'schedule.executor_dependency');

    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('processes', $restart ? 1 : 0);
    $this->assertDatabaseCount('schedules', 0);
    expect($this->agents->calls)->toBe([]);
})->with(['services', 'worker'])->with(['process.install_instance_definitions', 'schedule.install_instance_definitions', 'process.restart_instance']);

describe('task VM Process child admission', function (): void {
    beforeEach(function (): void {
        config(['orbit.pi.port' => 4274]);
        $this->vm = TaskVm::query()->create(['name' => 'tvm-process-proof', 'provider' => 'incus', 'state' => 'provisioning',
            'host_node_id' => $this->executor->id, 'node_id' => $this->node->id, 'wireguard_ip' => $this->node->wireguard_ip]);
        $this->piProcess = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'pi-server',
            'runtime' => 'systemd', 'working_directory' => '/home/orbit', 'restart_policy' => 'always', 'keep_alive' => true,
            'restart_on_deploy' => true, 'status' => 'provisioning', 'desired_state' => 'running',
            'runtime_config' => ['environment_file' => '', 'command' => ['/home/orbit/.local/bin/pi-server', 'serve',
                '--host=10.44.0.3', '--port=4274', '--token-file=/home/orbit/.pi/agent/orbit-token', '--allow-provider=orbit-task-vm']]]);
        $definition = ['executor' => 'swamp', 'completion' => InstanceRuntimeTestParent::class, 'execution' => InstanceRuntimeTestParent::class,
            'runs' => [], 'children' => [['kind' => 'process.create', 'node_from' => 'operation.node_id']],
            'completion_contract' => ['children_succeeded' => ['process.create']], 'result_fields' => ['ready']];
        app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'task_vm.prepare_runtime' => $definition]));
        $this->vmParent = app(StartOperationAction::class)->execute('task_vm.prepare_runtime', 'task_vm', $this->vm->id,
            $this->node->id, ['task_vm_id' => $this->vm->id], 'test', null);
        $this->vmParent->update(['state' => ['child_intents' => [['key' => 'pi-process-owner', 'kind' => 'process.create',
            'node_id' => $this->node->id, 'input' => ['process_id' => $this->piProcess->id]]]]]);
        $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'task_vm.prepare_runtime', 'worker' => 'vm-runtime',
            'operation_id' => $this->vmParent->id])->assertOk();
        $this->vmChildPath = '/api/v1/operation-executor/'.$this->vmParent->id.'/children';
        $this->vmChildRequest = ['worker' => 'vm-runtime', 'key' => 'pi-process-owner'];
    });

    it('admits and replays only the fixed Pi Process on the VM Node without executing it', function (): void {
        $id = $this->postJson($this->vmChildPath, $this->vmChildRequest)->assertAccepted()->json('data.id');
        $this->postJson($this->vmChildPath, $this->vmChildRequest)->assertOk()->assertJsonPath('data.id', $id);

        $child = Operation::query()->findOrFail($id);
        expect($child->parent_operation_id)->toBe($this->vmParent->id);
        expect($child->kind)->toBe('process.create');
        expect($child->target_type)->toBe('process');
        expect($child->target_id)->toBe($this->piProcess->id);
        expect($child->node_id)->toBe($this->vm->node_id);
        expect($child->input['desired'])->toBe('running');
        expect($this->vmParent->children()->count())->toBe(1);
        expect($this->agents->calls)->toBe([]);
    });

    it('refuses a Process or VM owned by another target', function (string $change): void {
        match ($change) {
            'instance' => $this->piProcess->update(['owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id]),
            'foreign-node' => $this->piProcess->update(['owner_id' => $this->executor->id]),
            'moved-vm' => $this->vm->update(['node_id' => $this->executor->id]),
            'no-node' => $this->vm->update(['node_id' => null]),
            'settled-vm' => $this->vm->update(['state' => 'ready']),
            'missing-vm' => $this->vm->delete(),
        };

        $this->postJson($this->vmChildPath, $this->vmChildRequest)->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
        expect($this->vmParent->children()->count())->toBe(0);
        expect($this->agents->calls)->toBe([]);
    })->with(['instance', 'foreign-node', 'moved-vm', 'no-node', 'settled-vm', 'missing-vm']);

    it('refuses a changed Pi Process definition before recording a child', function (string $field, mixed $value): void {
        if (str_starts_with($field, 'runtime_config.')) {
            $configuration = $this->piProcess->runtime_config;
            Arr::set($configuration, substr($field, strlen('runtime_config.')), $value);
            $this->piProcess->update(['runtime_config' => $configuration]);
        } else {
            $this->piProcess->forceFill([$field => $value])->save();
        }

        $this->postJson($this->vmChildPath, $this->vmChildRequest)->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
        expect($this->vmParent->children()->count())->toBe(0);
        expect($this->agents->calls)->toBe([]);
    })->with([
        'name' => ['name', 'other'], 'runtime' => ['runtime', 'docker'],
        'binary' => ['runtime_config.command.0', '/bin/sh'], 'host' => ['runtime_config.command.2', '--host=10.44.0.99'],
        'port' => ['runtime_config.command.3', '--port=3774'], 'token-file' => ['runtime_config.command.4', '--token-file=/tmp/token'],
        'provider' => ['runtime_config.command.5', '--allow-provider=other'], 'extra-argument' => ['runtime_config.command.6', '--unsafe'],
        'environment' => ['runtime_config.environment', ['EXTRA' => 'value']], 'account' => ['runtime_config.user', 'root'],
        'environment-file' => ['runtime_config.environment_file', '/tmp/env'], 'directory' => ['working_directory', '/tmp'],
        'restart' => ['restart_policy', 'never'], 'keep-alive' => ['keep_alive', false], 'restart-on-deploy' => ['restart_on_deploy', false],
        'source-definition' => ['source_definition_id', 'foreign-definition'],
        'stopped' => ['desired_state', 'stopped'], 'removing' => ['status', 'removing'], 'retired-runtime' => ['retired_runtime', 'docker'],
    ]);

    it('refuses prepared child input beyond the Process identity', function (): void {
        $state = $this->vmParent->fresh()->state;
        $state['child_intents'][0]['input']['command'] = ['/bin/sh'];
        $this->vmParent->update(['state' => $state]);

        $this->postJson($this->vmChildPath, $this->vmChildRequest)->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
        expect($this->vmParent->children()->count())->toBe(0);
        expect($this->agents->calls)->toBe([]);
    });

    it('refuses a task VM parent with a different target type', function (): void {
        $parent = clone $this->vmParent;
        $parent->target_type = 'instance';
        expect(fn () => app(ProcessRuntimeChildAdmission::class)->admit($parent,
            ['kind' => 'process.create', 'node_id' => $this->node->id, 'input' => ['process_id' => $this->piProcess->id]]))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_forbidden'));
        expect($this->vmParent->children()->count())->toBe(0);
    });

    it('keeps an Instance parent from adopting the Node-owned Pi Process', function (): void {
        $parent = clone $this->vmParent;
        $parent->kind = 'process.install_instance_definitions';
        $parent->target_type = 'instance';
        $parent->target_id = $this->instance->id;
        expect(fn () => app(ProcessRuntimeChildAdmission::class)->admit($parent,
            ['kind' => 'process.create', 'node_id' => $this->node->id, 'input' => ['process_id' => $this->piProcess->id]]))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_forbidden'));
        expect($this->vmParent->children()->count())->toBe(0);
    });

    it('refuses a restart child from task VM runtime preparation', function (): void {
        expect(fn () => app(ProcessRuntimeChildAdmission::class)->admit($this->vmParent,
            ['kind' => 'process.restart', 'node_id' => $this->node->id, 'input' => ['process_id' => $this->piProcess->id]]))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_forbidden'));
        expect($this->vmParent->children()->count())->toBe(0);
    });

});
