<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Operations\NativeRunCancellation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Infrastructure\Swamp\CancelSwampRun;
use App\Jobs\Operations\CancelNativeRun;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

const RUN = 'ea55ab37-bcbe-4459-81dc-cbb397e7d870';

/** A tasks.script of a group that started 20 minutes ago with a 360-second budget. */
function runningScript(?string $run = RUN, int $minutes = 20): Operation
{
    $group = Task::query()->create(['title' => 'Proof', 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
    $address = '10.44.0.'.(100 + $group->id);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vm-'.$group->id, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit']));

    return Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'input' => ['script' => 'scripts/dependencies', 'args' => ['routine'], 'run_budget_seconds' => 360], 'status' => OperationStatus::Running,
        'state' => $run === null ? [] : ['swamp_run_id' => $run], 'sequence' => 1, 'attempts' => 1, 'started_at' => now()->subMinutes($minutes)]);
}

it('asks Swamp to cancel the bound run when the Gateway times the Operation out', function (): void {
    Queue::fake();
    $operation = runningScript();

    $expired = app(ExpireOperationAction::class)->execute($operation);

    expect($expired->error_code)->toBe('operation.timed_out')
        ->and($expired->state['native_cancel_requested_at'] ?? null)->not->toBeNull();
    Queue::assertPushed(CancelNativeRun::class, fn (CancelNativeRun $job): bool => $job->operationId === $operation->id);
});

it('does not ask twice for the same expiry, and has nothing to cancel without a bound run', function (): void {
    Queue::fake();
    $expired = app(ExpireOperationAction::class)->execute(runningScript());

    expect(app(NativeRunCancellation::class)->request($expired->refresh()))->toBeFalse()
        ->and(app(NativeRunCancellation::class)->request(runningScript(null), again: true))->toBeFalse();
    Queue::assertPushed(CancelNativeRun::class, 1);
});

it('lets an operator cancel ask again for a run that outlives its timed out Operation', function (): void {
    Queue::fake();
    $expired = app(ExpireOperationAction::class)->execute(runningScript());

    app(CancelOperationAction::class)->execute($expired->refresh());

    Queue::assertPushed(CancelNativeRun::class, 2);
});

it('asks Swamp to cancel the timed out runs of a group that ends', function (): void {
    Queue::fake();
    $expired = app(ExpireOperationAction::class)->execute(runningScript());
    $state = $expired->state;
    unset($state['native_cancel_requested_at']);
    $expired->forceFill(['state' => $state])->save();
    $group = Task::topLevel()->findOrFail($expired->target_id);

    app(TaskOperations::class)->cancelOpen($group);

    Queue::assertPushed(CancelNativeRun::class, 2);
});

it('posts the cancellation of the run to Swamp from the queue', function (): void {
    config(['swamp.run_token' => 'token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);
    Http::fake(['https://swamp.orbit/*' => Http::response([], 200)]);
    $operation = runningScript();

    (new CancelNativeRun($operation->id))->handle(app(CancelSwampRun::class));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://swamp.orbit/api/v1/cancel/workflow-run/'.RUN);
});
