<?php

declare(strict_types=1);

use App\Actions\Instances\InstanceSmokeRecoveryAction;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationTargetBusy;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Instance;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

function instance_hold_holder(Instance $instance, string $kind, string $status): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'input' => [], 'status' => $status, 'sequence' => 1,
        'attempts' => $status === 'queued' ? 0 : 1, 'lock_key' => OperationStore::lockKey('instance', $instance->id)]);
}

function instance_hold_start(Instance $instance, array $supersedes = [], string $targetType = 'instance'): Operation
{
    return app(OperationStore::class)->create('instance.rename', $targetType, $instance->id, $instance->node_id, [], 'test', null, supersedes: $supersedes);
}

it('holds an Instance target by default and refuses a second start with the holder id', function (): void {
    $instance = dev935_instance();
    $first = instance_hold_start($instance);

    expect($first->lock_key)->toBe('instance:'.$instance->id)
        ->and(fn () => instance_hold_start($instance))->toThrow(function (OperationTargetBusy $busy) use ($first): void {
            expect([$busy->errorCode, $busy->status, $busy->details])->toBe(['operation.target_busy', 409, ['operation_id' => $first->id]]);
        });
    expect(Operation::query()->count())->toBe(1);
});

it('admits a new start once the holder releases the Instance', function (): void {
    $instance = dev935_instance();
    instance_hold_holder($instance, 'instance.rename', 'running')->update(['status' => 'succeeded', 'lock_key' => null]);

    expect(instance_hold_start($instance)->lock_key)->toBe('instance:'.$instance->id);
});

it('takes no default hold for another target type', function (): void {
    $instance = dev935_instance();
    instance_hold_holder($instance, 'instance.rename', 'running');

    expect(instance_hold_start($instance, targetType: 'instance_smoke')->lock_key)->toBeNull();
});

it('supersedes an unstarted holder of a listed kind before taking the hold', function (): void {
    $instance = dev935_instance();
    $holder = instance_hold_holder($instance, 'process.restart_instance', 'queued');

    $started = instance_hold_start($instance, ['process.restart_instance']);

    $holder->refresh();
    expect([$holder->status->value, $holder->error_code, $holder->lock_key])->toBe(['cancelled', 'operation.superseded', null])
        ->and($holder->cancel_requested_at)->not->toBeNull()
        ->and($started->lock_key)->toBe('instance:'.$instance->id);
});

it('keeps a started holder of a listed kind and refuses the start', function (): void {
    $instance = dev935_instance();
    $holder = instance_hold_holder($instance, 'process.restart_instance', 'running');

    expect(fn () => instance_hold_start($instance, ['process.restart_instance']))
        ->toThrow(fn (OperationTargetBusy $busy) => expect($busy->details)->toBe(['operation_id' => $holder->id]));
    expect($holder->refresh()->status->value)->toBe('running');
});

it('does not supersede a holder of an unlisted kind', function (): void {
    $instance = dev935_instance();
    $holder = instance_hold_holder($instance, 'process.restart_instance', 'queued');

    expect(fn () => instance_hold_start($instance, ['instance.deploy']))->toThrow(OperationTargetBusy::class);
    expect($holder->refresh()->status->value)->toBe('queued');
});

/** A deploy admitted through the store, before any Swamp submission: queued, 0 attempts, holding by default. */
function instance_hold_deploy(Instance $instance, string $trigger): Operation
{
    $private = ['$secret' => 'instance.deployment', 'id' => $instance->id];

    return app(OperationStore::class)->create('instance.deploy', 'instance', $instance->id, $instance->node_id,
        ['instance_id' => $instance->id, 'git_read' => $private, 'vp_materialize' => $private, 'environment_material' => $private,
            'definitions_material' => $private], $trigger, null);
}

it('lets smoke recovery see a queued automatic deploy through the Instance hold', function (string $trigger, string $status, bool $required): void {
    $instance = dev935_instance();
    $deploy = instance_hold_deploy($instance, $trigger);
    $deploy->update(['status' => $status]);

    expect(app(InstanceSmokeRecoveryAction::class)->redeployRequired($instance))->toBe($required);
})->with([
    'queued automatic' => [AutomaticDeployHistory::Trigger, 'queued', true],
    'queued Swamp automatic' => [AutomaticDeployHistory::SwampTrigger, 'queued', true],
    'running automatic' => [AutomaticDeployHistory::Trigger, 'running', false],
    'queued manual' => ['operator', 'queued', false],
]);

it('reports no redeploy when no deploy holds the Instance', function (): void {
    expect(app(InstanceSmokeRecoveryAction::class)->redeployRequired(dev935_instance()))->toBeFalse();
});

it('supersedes a queued automatic or manual deploy before the hold refuses the start', function (string $trigger): void {
    $instance = dev935_instance();
    $deploy = instance_hold_deploy($instance, $trigger);

    $started = instance_hold_start($instance, ['instance.deploy']);

    $deploy->refresh();
    expect([$deploy->status->value, $deploy->error_code, $deploy->lock_key])->toBe(['cancelled', 'operation.superseded', null])
        ->and($deploy->cancel_requested_at)->not->toBeNull()
        ->and($started->lock_key)->toBe('instance:'.$instance->id);
})->with(['automatic' => [AutomaticDeployHistory::Trigger], 'manual' => ['operator']]);

it('keeps a running deploy and refuses the superseding start with the deploy id', function (): void {
    $instance = dev935_instance();
    $deploy = instance_hold_deploy($instance, AutomaticDeployHistory::Trigger);
    $deploy->update(['status' => 'running', 'attempts' => 1]);

    expect(fn () => instance_hold_start($instance, ['instance.deploy']))
        ->toThrow(fn (OperationTargetBusy $busy) => expect($busy->details)->toBe(['operation_id' => $deploy->id]));
    expect($deploy->refresh()->status->value)->toBe('running');
});

it('keeps a queued deploy that Swamp already accepted', function (array $submitted): void {
    $instance = dev935_instance();
    $deploy = instance_hold_deploy($instance, AutomaticDeployHistory::Trigger);
    $deploy->update($submitted);

    expect(fn () => instance_hold_start($instance, ['instance.deploy']))
        ->toThrow(fn (OperationTargetBusy $busy) => expect($busy->details)->toBe(['operation_id' => $deploy->id]));
    expect($deploy->refresh()->status->value)->toBe('queued');
})->with([
    'native run bound' => [['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]],
    'dispatched' => [['dispatched_at' => now()]],
]);

it('never submits a deploy that a start superseded between its commit and its Swamp start', function (): void {
    $instance = dev935_instance();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'superseded.fixture']);
    $deploy = instance_hold_deploy($instance, AutomaticDeployHistory::Trigger);
    instance_hold_start($instance, ['instance.deploy']);

    app(StartSwampRun::class)->start($deploy);

    $deploy->refresh();
    expect($socket->sent)->toBe([])
        ->and([$deploy->status->value, $deploy->error_code, $deploy->dispatched_at])->toBe(['cancelled', 'operation.superseded', null])
        ->and($deploy->state)->not->toHaveKey('swamp_run_id');
});
