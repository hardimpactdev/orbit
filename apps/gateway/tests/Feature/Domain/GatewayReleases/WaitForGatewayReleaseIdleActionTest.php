<?php

declare(strict_types=1);

use App\Models\Operation;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Sleep;

uses(LazilyRefreshDatabase::class);

/** An Operation whose state the release guard must read from the database on every poll. */
function releaseBlocker(string $kind, string $status = 'running'): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'node', 'target_id' => 1,
        'status' => $status, 'input' => [], 'triggered_by' => 'test', 'state' => []]);
}

it('proceeds immediately when only terminal or unrelated Operations exist', function (): void {
    Sleep::fake();
    foreach (['succeeded', 'failed', 'cancelled', 'interrupted'] as $status) {
        releaseBlocker('instance.create', $status);
        releaseBlocker('instance.deploy', $status);
        releaseBlocker('conn.setup', $status);
        releaseBlocker('process.restart', $status);
    }
    foreach (['gateway.release', 'task_vm.image_refresh', 'instance.destroy', 'connection.setup', 'processes.start'] as $kind) {
        releaseBlocker($kind);
    }

    $this->artisan('gateway:release:wait-idle')->assertSuccessful();

    Sleep::assertNeverSlept();
});

it('waits then refuses each protected kind family and active status after ten minutes', function (string $kind, string $status): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $blocker = releaseBlocker($kind, $status);

    $this->artisan('gateway:release:wait-idle')
        ->expectsOutputToContain('gateway.release_busy: Active Operations after 600 seconds: '.$blocker->id)
        ->assertFailed();

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 5.0, 120);
    expect($blocker->refresh()->status->value)->toBe($status);
})->with(['instance.create', 'instance.deploy', 'instance.deploy_production', 'instance.deploy.preview', 'conn.setup', 'process.restart'])
    ->with(['queued', 'running', 'waiting']);

it('proceeds on the next poll after the blocker finishes', function (): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $blocker = releaseBlocker('instance.create');
    Sleep::whenFakingSleep(fn () => $blocker->update(['status' => 'succeeded']));

    $this->artisan('gateway:release:wait-idle')->assertSuccessful();

    Sleep::assertSequence([Sleep::for(5)->seconds()]);
});

it('reports the remaining blocker IDs from the final poll', function (): void {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    $finished = releaseBlocker('instance.create');
    $first = releaseBlocker('conn.setup', 'queued');
    $second = releaseBlocker('process.start', 'waiting');
    Sleep::whenFakingSleep(fn () => $finished->update(['status' => 'succeeded']));

    $this->artisan('gateway:release:wait-idle')
        ->expectsOutputToContain('gateway.release_busy: Active Operations after 600 seconds: '.$first->id.', '.$second->id)
        ->assertFailed();

    Sleep::assertSleptTimes(120);
});
