<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceRollbackAction;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceRollbackProjection;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    config(['swamp.run_token' => 'native.rollback.fixture']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);

});

function native_rollback_instance(): Instance
{
    [, , , $instance] = deployment_api_fixture();
    $instance->node->update(['architecture' => 'x86_64']);
    $instance->update(['production_user' => 'orbit-app-'.$instance->project_id,
        'production_home' => '/home/orbit-app-'.$instance->project_id,
        'checkout_path' => '/home/orbit-app-'.$instance->project_id.'/releases/current',
        'source_layout' => 'release', 'selected_php_version' => null]);

    return $instance->fresh();
}

it('keeps development rollback unavailable before observations or admission', function (): void {
    $this->mock(ProductionDeployment::class)->shouldNotReceive('releases');
    expect(fn () => app(StartInstanceRollbackAction::class)->execute(dev935_instance(), 'current', 'operator', null))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('deployment_config.unavailable'));
    expect(Operation::query()->count())->toBe(0)->and(InstanceDeployment::query()->count())->toBe(0);
});

it('admits one native rollback with immutable production account and no Git or environment material', function (): void {
    $instance = native_rollback_instance();
    $this->mock(ProductionDeployment::class)->shouldReceive('releases')->once()->andReturn(new DeploymentReleaseState(['current'], 'current'));
    $operation = app(StartInstanceRollbackAction::class)->execute($instance, 'current', 'operator', null);

    expect($operation->kind)->toBe('instance.rollback')
        ->and($operation->input['deployment']['variant'])->toBe('rollback')
        ->and($operation->input['deployment']['user'])->toBe('orbit-app-'.$instance->project_id)
        ->and($operation->input['runtime_definitions'])->toBe(['processes' => [], 'schedules' => [], 'restart' => []])
        ->and($operation->input)->not->toHaveKeys(['git_read', 'vp_materialize', 'environment_material', 'environment', 'agent_sha256'])
        ->and(Operation::query()->count())->toBe(1)->and(InstanceDeployment::query()->count())->toBe(0)
        ->and($instance->fresh()->runtime_definitions_captured_at)->toBeNull();
    $public = app(InstanceRollbackProjection::class)->input($operation);
    $instance->update(['production_user' => 'later-change', 'production_home' => '/home/later-change']);
    expect(app(InstanceRollbackProjection::class)->input($operation))->toBe($public)
        ->and($public['release'])->toBe('current');
});

it('refuses an unretained release before creating a native root', function (): void {
    $instance = native_rollback_instance();
    $this->mock(ProductionDeployment::class)->shouldReceive('releases')->once()->andReturn(new DeploymentReleaseState(['current'], 'current'));
    expect(fn () => app(StartInstanceRollbackAction::class)->execute($instance, 'removed', 'operator', null))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('rollback.release_not_retained'));
    expect(Operation::query()->count())->toBe(0)->and(InstanceDeployment::query()->count())->toBe(0);
});

it('refuses a production account that does not belong to the admitted Project', function (): void {
    $instance = native_rollback_instance();
    $instance->update(['production_user' => 'other-user', 'production_home' => '/home/other-user']);
    $this->mock(ProductionDeployment::class)->shouldNotReceive('releases');
    expect(fn () => app(StartInstanceRollbackAction::class)->execute($instance, 'current', 'operator', null))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('deployment_config.unavailable'));
    expect(Operation::query()->count())->toBe(0);
});

it('rolls native rollback admission back with its owning transaction', function (): void {
    $instance = native_rollback_instance();
    $this->mock(ProductionDeployment::class)->shouldReceive('releases')->once()->andReturn(new DeploymentReleaseState(['current'], 'current'));
    expect(fn () => DB::transaction(function () use ($instance): void {
        app(StartInstanceRollbackAction::class)->execute($instance, 'current', 'operator', null);
        throw new RuntimeException('rollback root transaction');
    }))->toThrow(RuntimeException::class, 'rollback root transaction');
    expect(Operation::query()->count())->toBe(0)->and(InstanceDeployment::query()->count())->toBe(0);
});

it('projects the native retained-release result once without runtime validation or additional Operations', function (): void {
    $instance = native_rollback_instance();
    $operation = Operation::query()->create(['kind' => 'instance.rollback', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => OperationStatus::Running, 'lock_key' => 'instance:'.$instance->id,
        'input' => ['instance_id' => $instance->id, 'release' => 'retained', 'platform' => 'linux', 'architecture' => 'x86_64',
            'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance, rollback: true),
            'runtime_definitions' => ['processes' => [], 'schedules' => [], 'restart' => []]],
        'state' => ['swamp_run_id' => '402775cd-6ab4-46d1-a92e-f84ae368fe62']]);
    $body = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-31T10:00:00Z', 'finished_at' => '2026-10-31T10:00:01Z',
        'result' => ['release' => ['name' => 'retained', 'commit' => str_repeat('b', 40)],
            'selected_release' => ['name' => 'retained', 'commit' => str_repeat('b', 40)], 'selection_confirmed' => true,
            'commit' => str_repeat('b', 40), 'failed_step' => null, 'restart' => [], 'private' => 'never project']];
    $projection = app(InstanceRollbackProjection::class);
    $first = $projection->apply($operation, $body);
    $projection->apply($first, $body);
    expect($first->status)->toBe(OperationStatus::Succeeded)->and($first->lock_key)->toBeNull()
        ->and($first->result)->not->toHaveKey('private')
        ->and($instance->fresh()->checkout_path)->toBe($instance->production_home.'/releases/retained')
        ->and(InstanceDeployment::query()->sole()->kind)->toBe('rollback')
        ->and(InstanceDeployment::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});
