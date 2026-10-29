<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceLifecycleCompletion;
use App\Domain\Instances\InstanceLifecycleExecution;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class PreparationRouteAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute('route.update', 'route', $intent['input']['route_id'],
            $intent['node_id'], $intent['input'], null, null);
    }
}

beforeEach(function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(),
        'instance.rename' => ['executor' => 'swamp', 'execution' => InstanceLifecycleExecution::class,
            'completion' => InstanceLifecycleCompletion::class,
            'runs' => [['transport' => 'instance_lifecycle', 'name' => 'instance.inspect-development-source', 'resumable' => true],
                ['transport' => 'gateway', 'name' => 'instance.lifecycle-state', 'resumable' => true]],
            'children' => [['kind' => 'route.update', 'node_from' => 'operation.node_id']],
            'completion_contract' => ['step' => 'rename-inspect', 'transport' => 'instance_lifecycle',
                'name' => 'instance.inspect-development-source', 'result' => ['source_verified' => true]],
            'result_fields' => ['source_verified', 'branch', 'error_code']],
        'route.update' => ['executor' => 'swamp', 'admission' => PreparationRouteAdmission::class,
            'completion' => InstanceLifecycleCompletion::class,
            'runs' => [['transport' => 'gateway', 'name' => 'preparation.ready']],
            'completion_contract' => ['step' => 'ready', 'transport' => 'gateway', 'name' => 'preparation.ready', 'result' => []],
            'result_fields' => ['error_code']],
    ]));
    $node = static fn (string $name, string $ip): Node => Node::query()->create(['name' => $name, 'wireguard_ip' => $ip,
        'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10']);
    $gateway = $node('gateway', '10.44.0.1');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->dev = $node('dev', '10.44.0.9');
    $this->dev->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $services = $node('services', '10.44.0.17');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $project = Project::query()->create(['name' => 'Preparation proof', 'slug' => 'preparation-proof', 'root' => 'public',
        'repository_url' => 'https://github.com/example/preparation.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->dev->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/preparation/proof', 'branch' => 'feature', 'status' => 'active']);
    $this->operation = app(StartOperationAction::class)->execute('instance.rename', 'instance', $this->instance->id, $this->dev->id,
        ['instance_id' => $this->instance->id, 'project_id' => $project->id, 'repository' => $project->repository_url,
            'repository_identity' => $project->repository_identity, 'checkout' => $this->instance->checkout_path,
            'source_root' => 'public', 'user' => 'orbit', 'group' => 'orbit', 'branch' => 'feature', 'domain' => null], null, null);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'instance.rename', 'worker' => 'preparation-worker'])
        ->assertOk()->assertJsonPath('data.id', $this->operation->id);
    $descriptor = app(InstanceLifecycleExecution::class)->context($this->operation->refresh())['descriptors'][0];
    $this->run = ['worker' => 'preparation-worker', 'key' => $descriptor['key'], 'transport' => $descriptor['transport'],
        'name' => $descriptor['name'], 'input' => $descriptor['input']];
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    $this->prove = function (): array {
        $runner = Mockery::mock(AgentCommandRunner::class);
        $runner->shouldReceive('run')->once()->andReturn(['origin' => 'https://github.com/example/preparation.git', 'branch' => 'feature']);
        app()->instance(AgentCommandRunner::class, $runner);
        $result = $this->postJson($this->path.'/run', $this->run)->assertOk()->json('data');
        $this->postJson($this->path.'/step', ['worker' => 'preparation-worker', 'key' => 'rename-proof',
            'name' => 'rename-inspect', 'status' => 'succeeded', 'result' => $result])->assertOk();

        return $result;
    };
});

it('refuses executor input that differs from the Gateway descriptor before dispatch', function (): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $run = $this->run;
    $run['input']['checkout'] = '/srv/apps/foreign/proof';

    $this->postJson($this->path.'/run', $run)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->operation->refresh()->state['executor_runs'] ?? [])->toBe([]);
});

it('keeps the recorded binding pinned when stored configuration changes', function (): void {
    ($this->prove)();
    $this->operation->refresh()->update(['input' => [...$this->operation->input, 'user' => 'changed-owner']]);

    $descriptor = app(InstanceLifecycleExecution::class)->context($this->operation->refresh())['descriptors'][0];

    expect($descriptor['input'])->toBe($this->run['input']);
    $this->postJson($this->path.'/run', $this->run)->assertOk()->assertJsonPath('data.source_verified', true);
});

it('rejects lifecycle proof with a refused or mismatched run or step', function (string $change): void {
    ($this->prove)();
    $operation = $this->operation->refresh();
    $state = $operation->state;
    match ($change) {
        'error' => $state['executor_runs']['rename-inspect']['result']['error_code'] = 'instance.source_busy',
        'transport' => $state['executor_runs']['rename-inspect']['transport'] = 'gateway',
        'name' => $state['executor_runs']['rename-inspect']['name'] = 'instance.remove-production-home',
        'step' => $state['executor_runs']['rename-inspect']['step'] = 'another-step',
        'input' => $state['executor_runs']['rename-inspect']['input']['user'] = 'another-owner',
        'missing-step' => $operation->steps()->delete(),
    };
    $operation->update(['state' => $state]);

    expect(fn () => app(InstanceLifecycleExecution::class)->proven($operation, 'rename-inspect'))
        ->toThrow(ResourceOperationException::class);
})->with(['error', 'transport', 'name', 'step', 'input', 'missing-step']);

it('requires the exact successful child kind', function (string $status, string $expectedKind): void {
    ($this->prove)();
    $route = Route::query()->create(['domain' => 'preparation.test', 'node_id' => $this->dev->id,
        'project_id' => $this->instance->project_id, 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $this->operation->refresh()->update(['input' => [...$this->operation->input, 'domain' => 'renamed.test', 'route_id' => $route->id]]);
    $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->operation, ['key' => 'route-update'])['operation']);
    $child->update(['status' => $status]);

    expect(fn () => app(InstanceLifecycleExecution::class)->assertChild($this->operation, 'route-update', $expectedKind))
        ->toThrow(ResourceOperationException::class);
})->with([['succeeded', 'instance.run_steps'], ['queued', 'route.update'], ['failed', 'route.update'], ['cancelled', 'route.update']]);
