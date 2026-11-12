<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Domain\InstanceRuntime\InstanceRuntimeUpdate;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

function runtimeUpdateFixture(): Instance
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
    app(InstanceContainer::class)->reserve($instance, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);

    return $instance;
}

it('admits a scoped secret-free Operation through the authenticated API', function (): void {
    $instance = runtimeUpdateFixture();
    $instance->node->accessibleNodes()->syncWithoutDetaching([$instance->node_id]);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip])
        ->postJson('/api/v1/instances/'.$instance->id.'/runtime')
        ->assertAccepted()->assertJsonPath('data.kind', InstanceRuntimeUpdate::Kind);
    $operation = Operation::query()->where('kind', InstanceRuntimeUpdate::Kind)->sole();
    $control = containerDefaultNode('control', '10.44.0.209', false);
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $input = app(InstanceRuntimeUpdate::class)->input($operation);
    expect($operation->lock_key)->toBe(OperationStore::lockKey('instance', $instance->id))
        ->and($input['intent']['container'])->toBe($operation->input['container'])
        ->and($input['intent']['runtime_update'])->toBe([
            'path' => '/opt/orbit-runtime',
            'version' => app(InstanceHostIntent::class)->for($instance->node)['folders']['runtime']['version'],
        ])
        ->and($input)->not->toHaveKey('execution_secrets')
        ->and(app(DirectOperation::class)->workflowInputs($operation)['execution_secrets'])->toEqual((object) [])
        ->and($input['intent'])->toHaveKey('ssh_execution');
});

it('requires the caller Node access edge', function (): void {
    $instance = runtimeUpdateFixture();
    $caller = containerDefaultNode('caller', '10.44.0.8', false);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/instances/'.$instance->id.'/runtime')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->where('kind', InstanceRuntimeUpdate::Kind)->exists())->toBeFalse();
});

it('refuses unsupported or busy placement before starting work', function (string $case, string $code): void {
    $instance = runtimeUpdateFixture();
    match ($case) {
        'checkout' => $instance->runtime()->delete(),
        'production' => $instance->node->roles()->update(['role' => RoleName::AppProd]),
        'no app-dev' => $instance->node->roles()->delete(),
        'ambiguous roles' => $instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']),
        'stopped' => $instance->runtime()->update(['power_state' => 'stopped']),
        'lifecycle' => $instance->update(['status' => 'checkout_prepared']),
        'process' => Operation::query()->create(['kind' => 'process.restart', 'target_type' => 'process',
            'target_id' => $instance->processes()->create(['name' => 'worker', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['true']], 'status' => 'active'])->id,
            'status' => 'running', 'input' => [], 'state' => []]),
        default => Operation::query()->create(['kind' => $case, 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'lock_key' => OperationStore::lockKey('instance', $instance->id), 'status' => 'running', 'input' => [], 'state' => []]),
    };
    expect(fn () => app(InstanceRuntimeUpdate::class)->start($instance, null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->where('kind', InstanceRuntimeUpdate::Kind)->count())->toBe(0);
})->with([
    ['checkout', 'operation.kind_unavailable'],
    ['production', 'instance.host_unavailable'], ['no app-dev', 'instance.host_unavailable'], ['ambiguous roles', 'instance.host_unavailable'],
    ['stopped', 'process.target_inactive'], ['lifecycle', 'instance.lifecycle_busy'], ['process', 'instance.lifecycle_busy'],
    ['instance.deploy', 'operation.target_busy'], ['instance.migrate', 'operation.target_busy'], ['instance.destroy', 'operation.target_busy'],
]);

it('holds Gateway release idle until runtime work settles', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $operation = Operation::query()->create(['kind' => InstanceRuntimeUpdate::Kind, 'target_type' => 'instance', 'target_id' => 1,
        'status' => 'running', 'input' => [], 'state' => []]);
    Sleep::whenFakingSleep(fn () => $operation->update(['status' => 'succeeded']));
    app(WaitForGatewayReleaseIdleAction::class)->execute();
    Sleep::assertSleptTimes(1);
});

it('preserves the converted and unchanged public store receipts', function (string $status): void {
    $instance = runtimeUpdateFixture();
    $operation = app(InstanceRuntimeUpdate::class)->start($instance, null, null);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'runtime-proof']])->save();
    $result = ['status' => $status, 'versions' => ['22.23.5', '24.22.1'],
        'pins' => [['pin_source' => '.nvmrc', 'pinned' => '22', 'version' => '22.23.5', 'directory' => '/srv/workspace']]];
    $data = ['status' => 'succeeded', 'run_id' => 'runtime-proof', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $result];
    expect(app(InstanceRuntimeUpdate::class)->apply($operation, $data)->result)->toBe($result)
        ->and($instance->runtime()->sole()->power_state->value)->toBe('running');
})->with(['converted', 'unchanged']);

it('retains the named preparation failure and the public pin reason', function (): void {
    $instance = runtimeUpdateFixture();
    $operation = app(InstanceRuntimeUpdate::class)->start($instance, null, null);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'runtime-failure']])->save();
    $result = ['error_code' => 'instance.node_runtime_prepare_failed', 'error_message' => '.nvmrc pins Node 22: Node preparation timed out'];
    $data = ['status' => 'failed', 'run_id' => 'runtime-failure', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $result];
    $failed = app(InstanceRuntimeUpdate::class)->apply($operation, $data);
    expect($failed->error_code)->toBe('instance.node_runtime_prepare_failed')
        ->and($failed->result)->toBe($result);
});

it('replays the same admission key without starting another Operation', function (): void {
    $instance = runtimeUpdateFixture();
    $instance->node->accessibleNodes()->syncWithoutDetaching([$instance->node_id]);
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $first = $this->postJson('/api/v1/instances/'.$instance->id.'/runtime', [], ['Idempotency-Key' => 'runtime-proof'])->assertAccepted();
    $again = $this->postJson('/api/v1/instances/'.$instance->id.'/runtime', [], ['Idempotency-Key' => 'runtime-proof'])->assertAccepted();
    expect($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Operation::query()->where('kind', InstanceRuntimeUpdate::Kind)->count())->toBe(1);
});
