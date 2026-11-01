<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function instanceAlertOperation(string $kind = 'instance.create'): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => 782,
        'status' => OperationStatus::Running, 'sequence' => 1, 'input' => ['password' => 'never-include-input'],
        'state' => ['swamp_run_id' => 'fde9e689-c7bd-45ed-af24-71417a75ac49']]);
}

beforeEach(function (): void {
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/instance-alert',
        'orbit.releases.alert_webhook_secret' => 'fixture-signing-key']);
});

it('alerts both kinds immediately after commit even without a source commit', function (string $kind): void {
    Http::fake(['https://hooks.example.test/instance-alert' => Http::response('', 200)]);
    $operation = instanceAlertOperation($kind);
    DB::beginTransaction();
    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed,
        'error_code' => 'instance.create_source_failed', 'result' => ['failed_step' => 'create-mint', 'output_tail' => 'never-include-output']]);
    Http::assertNothingSent();
    DB::commit();

    Http::assertSent(function (Request $request) use ($operation, $kind): bool {
        $body = $request->body();
        $timestamp = $request->header('X-Orbit-Timestamp')[0];

        return $request['event'] === 'instance.operation_failed' && $request['operation_id'] === $operation->id
            && $request['kind'] === $kind && $request['instance_id'] === 782
            && $request['failed_step'] === 'create-mint' && $request['root_run_id'] === 'fde9e689-c7bd-45ed-af24-71417a75ac49'
            && ! str_contains($body, 'never-include')
            && $request->header('X-Orbit-Signature') === ['sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'fixture-signing-key')];
    });
    $activity = Activity::query()->where('command', 'instance:failure-alert')->sole();
    expect($activity->status)->toBe('succeeded');
    expect($activity->properties['webhook'])->toBe(['outcome' => 'done']);
    app(OperationStore::class)->change($operation, ['result' => $operation->result]);
    Http::assertSentCount(1);
})->with(['instance.create', 'instance.deploy']);

it('does not alert rolled back changes', function (): void {
    Http::fake();
    $operation = instanceAlertOperation();
    DB::beginTransaction();
    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed]);
    DB::rollBack();
    Http::assertNothingSent();
    expect(Activity::query()->where('command', 'instance:failure-alert')->exists())->toBeFalse();
});

it('ignores success cancellation and unrelated kinds', function (string $kind, OperationStatus $status): void {
    Http::fake();
    $operation = instanceAlertOperation($kind);
    app(OperationStore::class)->change($operation, ['status' => $status]);
    Http::assertNothingSent();
    expect(Activity::query()->where('command', 'instance:failure-alert')->exists())->toBeFalse();
})->with([
    ['instance.create', OperationStatus::Succeeded], ['instance.deploy', OperationStatus::Cancelled],
    ['instance.destroy', OperationStatus::Failed],
]);

it('records a failed webhook without preventing terminal failure', function (): void {
    Http::fake(['https://hooks.example.test/instance-alert' => Http::response('', 503)]);
    $operation = instanceAlertOperation();
    app(OperationStore::class)->change($operation, ['status' => OperationStatus::Interrupted]);
    expect($operation->fresh()->status)->toBe(OperationStatus::Interrupted);
    expect(Activity::query()->where('command', 'instance:failure-alert')->sole()->error_code)->toBe('instance.alert_webhook_failed');
    Http::assertSentCount(1);
});
