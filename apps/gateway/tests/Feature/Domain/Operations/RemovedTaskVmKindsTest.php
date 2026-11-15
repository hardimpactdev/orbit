<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationBudgetTable;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RetiredOperationSettlement;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;

uses(LazilyRefreshDatabase::class);

/*
 * The task VM image refresh and the hub converge left the registry (docs/reference/old-paths.md). Their old Operations stay in the
 * database as history: lists and shows render them, the release's idle guard does not wait for them, and the reconcile pass ends an
 * open one by its cancel request or its budget, as for every retired kind.
 */
const REMOVED_TASK_VM_KINDS = ['task_vm.image_refresh' => 'task_vm_host', 'task_vm.hub_converge' => 'node_firewall'];

beforeEach(function (): void {
    $this->gateway = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
});

/** An Operation of a removed kind as the old Gateway stored it. */
function removedTaskVmOperation(string $kind, OperationStatus $status, int $minutes = 90, array $state = [], int $attempts = 0): Operation
{
    $terminal = $status->terminal();

    return Operation::query()->create(['kind' => $kind, 'target_type' => REMOVED_TASK_VM_KINDS[$kind], 'target_id' => 9,
        'node_id' => Node::query()->value('id'), 'input' => ['commit' => str_repeat('a', 40)], 'status' => $status,
        'result' => $status === OperationStatus::Succeeded ? ['refreshed' => true, 'build_id' => 41] : null,
        'error_code' => $status === OperationStatus::Failed ? 'task_vm.image_refresh_failed' : null,
        'lock_key' => $terminal ? null : REMOVED_TASK_VM_KINDS[$kind].':9', 'state' => $state, 'attempts' => $attempts,
        'started_at' => now()->subMinutes($minutes), 'finished_at' => $terminal ? now()->subMinutes($minutes - 5) : null]);
}

it('has no registry entry, chart or budget for the removed kinds, and no artisan command of theirs', function (string $kind): void {
    $charts = array_filter(glob(base_path('../../docs/cli/*/*.mmd')) ?: [], static fn (string $chart): bool => str_contains((string) file_get_contents($chart), "%% kind: {$kind}\n"));

    expect(OperationKinds::names())->not->toContain($kind)
        ->and(app(DirectOperation::class)->definition($kind))->toBeNull()
        ->and(OperationBudgetTable::SecondsByKind)->not->toHaveKey($kind)
        ->and(glob(base_path('../../docs/cli/*/*.mmd')))->not->toBeEmpty()
        ->and($charts)->toBe([])
        ->and(array_keys(Artisan::all()))->not->toContain('task-vms:prepare-hub')->not->toContain('task-vms:image-builds')->not->toContain('task-vms:list-image-builds');
})->with(array_keys(REMOVED_TASK_VM_KINDS));

it('lists and shows an old Operation of a removed kind in every terminal state', function (string $kind, OperationStatus $status): void {
    $operation = removedTaskVmOperation($kind, $status);
    $api = test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    $list = $api->getJson('/api/v1/operations?kind='.$kind)->assertOk();
    $show = $api->getJson('/api/v1/operations/'.$operation->id)->assertOk();

    expect(array_column($list->json('data'), 'id'))->toBe([$operation->id])
        ->and($list->json('data.0'))->toMatchArray(['kind' => $kind, 'status' => $status->value, 'poll_after_seconds' => null])
        ->and($show->json('data'))->toMatchArray(['id' => $operation->id, 'kind' => $kind, 'status' => $status->value])
        // The summary that Conn and the web views read renders it too.
        ->and(OperationData::summary($operation->refresh())->toArray())->toMatchArray(['kind' => $kind, 'status' => $status->value]);
})->with(array_keys(REMOVED_TASK_VM_KINDS))
    ->with([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted]);

it('leaves a finished Operation of a removed kind as it is on a reconcile pass', function (string $kind, OperationStatus $status): void {
    $operation = removedTaskVmOperation($kind, $status);
    $before = $operation->refresh()->toArray();

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect($operation->refresh()->toArray())->toBe($before);
})->with(array_keys(REMOVED_TASK_VM_KINDS))
    ->with([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled, OperationStatus::Interrupted]);

it('ends an open Operation of a removed kind at its budget on a reconcile pass, and frees its target', function (string $kind): void {
    $young = removedTaskVmOperation($kind, OperationStatus::Running, minutes: 5);
    $young->forceFill(['target_id' => 10, 'lock_key' => REMOVED_TASK_VM_KINDS[$kind].':10'])->save();
    $stale = removedTaskVmOperation($kind, OperationStatus::Running, minutes: 31, state: ['swamp_run_id' => 'ea55ab37-bcbe-4459-81dc-cbb397e7d870'], attempts: 1);

    app()->call([new ReconcileSwampOperations, 'handle']);

    expect(RetiredOperationSettlement::retired($stale))->toBeTrue()
        ->and($stale->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($stale->error_code)->toBe('operation.timed_out')
        ->and($stale->lock_key)->toBeNull()
        ->and($young->refresh()->status)->toBe(OperationStatus::Running);
})->with(array_keys(REMOVED_TASK_VM_KINDS));

it('cancels an open Operation of a removed kind on a cancel request', function (string $kind): void {
    $operation = removedTaskVmOperation($kind, OperationStatus::Running, minutes: 5);

    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($operation->refresh()->lock_key)->toBeNull();
})->with(array_keys(REMOVED_TASK_VM_KINDS));

it('does not hold the release for an open Operation of a removed kind', function (string $kind, OperationStatus $status): void {
    Sleep::fake();
    removedTaskVmOperation($kind, $status, minutes: 5);

    test()->artisan('gateway:release:wait-idle')->assertSuccessful();

    Sleep::assertNeverSlept();
})->with(array_keys(REMOVED_TASK_VM_KINDS))
    ->with([OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting]);
