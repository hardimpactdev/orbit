<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationHealth;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
});

function healthOperation(string $kind = 'firewall.allow', string $status = 'queued'): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'node', 'target_id' => 999,
        'status' => $status, 'sequence' => 1, 'attempts' => 0, 'lock_key' => 'node:'.fake()->uuid(),
        'input' => ['sentinel' => 'health-private-fixture']]);
}

it('reports every Swamp family once per health change without changing its work', function (string $kind): void {
    $operation = healthOperation($kind);
    $before = $operation->refresh()->getRawOriginal();
    $this->travel(4)->minutes();
    expect(OperationData::fromModel($operation)->assistance)->toBeNull();
    $this->travel(1)->minutes();
    expect(OperationData::fromModel($operation)->assistance)->toBe(['error_code' => 'operation.executor_unclaimed']);
    $this->artisan('orbit:operations-check')->expectsOutputToContain('operation.executor_unclaimed: Operation '.$operation->id)->assertFailed();
    $this->artisan('orbit:operations-check')->assertFailed();
    expect(Activity::query()->where('command', 'operation:health')->count())->toBe(1)
        ->and($operation->refresh()->getRawOriginal())->toBe($before)
        ->and(json_encode(Activity::query()->sole()->properties))->not->toContain('health-private-fixture');
    $operation->update(['status' => 'running', 'started_at' => now(), 'sequence' => 2]);
    $this->artisan('orbit:operations-check')->assertSuccessful();
    $this->artisan('orbit:operations-check')->assertSuccessful();
    expect(Activity::query()->where('command', 'operation:health')->count())->toBe(2);
    Http::assertNothingSent();
})->with(['firewall.allow', 'instance.deploy', 'conn.pairing_revoke', 'vault.sync', 'metrics.reconcile']);

it('reports a waiting parent whose child has no executor', function (): void {
    $parent = healthOperation(status: 'waiting');
    $child = healthOperation();
    $child->forceFill(['parent_operation_id' => $parent->id])->save();
    $this->travel(6)->minutes();
    $parent->update(['sequence' => 2]);
    expect(OperationData::fromModel($parent)->assistance)->toBe(['error_code' => 'operation.executor_unclaimed']);
    $child->update(['status' => 'running', 'started_at' => now()]);
    expect(OperationData::fromModel($parent)->assistance)->toBeNull();
});

it('reports running and waiting work that has not advanced for fifteen minutes', function (string $status): void {
    $operation = healthOperation(status: $status);
    $this->travel(14)->minutes();
    expect(OperationData::fromModel($operation)->assistance)->toBeNull();
    $this->travel(1)->minutes();
    expect(OperationData::fromModel($operation)->assistance)->toBe(['error_code' => 'operation.no_progress']);
    $operation->update(['sequence' => 2]);
    expect(OperationData::fromModel($operation)->assistance)->toBeNull();
})->with(['running', 'waiting']);

it('reports churn only after three attempts', function (): void {
    $operation = healthOperation(status: 'running');
    $operation->update(['attempts' => 3]);
    expect(OperationData::fromModel($operation)->assistance)->toBeNull();
    $operation->update(['attempts' => 4]);
    expect(OperationData::fromModel($operation)->assistance)->toBe(['error_code' => 'operation.reclaim_churn']);
});

it('clears terminal alerts once and ignores work outside Swamp', function (): void {
    $operation = healthOperation();
    $this->travel(6)->minutes();
    app(OperationHealth::class)->check();
    $operation->update(['status' => 'cancelled']);
    $this->travel(20)->minutes();
    healthOperation('legacy.unregistered')->update(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
    expect(app(OperationHealth::class)->check())->toBe([])
        ->and(app(OperationHealth::class)->check())->toBe([])
        ->and(OperationData::fromModel($operation)->assistance)->toBeNull()
        ->and(Activity::query()->where('command', 'operation:health')->count())->toBe(2);
});

it('registers one fleet health schedule instead of family-specific copies', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $events = collect(app(Schedule::class)->events());
    $commands = $events->map(static fn ($event): string => (string) ($event->description ?? $event->command));
    expect($commands->filter(static fn (string $command): bool => str_contains($command, 'orbit:operations-check')))->toHaveCount(1)
        ->and($commands->filter(static fn (string $command): bool => str_contains($command, 'orbit:conn-operations-check') || str_contains($command, 'orbit:instance-deployment-operations-check')))->toBeEmpty();
});

it('alerts once for a bound direct footprint waiting on its offline target worker without changing the intent', function (): void {
    $operation = healthOperation('node.converge');
    $operation->update(['lock_key' => null, 'worker' => null, 'input' => ['native_footprint' => true],
        'state' => ['node_converge_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $before = $operation->refresh()->getRawOriginal();
    $this->travel(5)->minutes();
    expect(OperationData::fromModel($operation)->assistance)->toBe(['error_code' => 'operation.executor_unclaimed']);
    $this->artisan('orbit:operations-check')->expectsOutputToContain('operation.executor_unclaimed: Operation '.$operation->id)->assertFailed();
    $this->artisan('orbit:operations-check')->assertFailed();
    expect(Activity::query()->where('command', 'operation:health')->count())->toBe(1);
    expect($operation->fresh()->getRawOriginal())->toBe($before);
    expect($operation->children()->count())->toBe(0);
    Http::assertNothingSent();
});
