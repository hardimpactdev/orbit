<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskTickClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

describe('one tick observation', function (): void {
    it('reads the time from the versioned record and leaves no compatibility key', function (): void {
        $this->travelTo(Carbon::parse('2026-10-07T06:00:00Z'));
        $clock = new TaskTickClock;

        $clock->record();

        expect(Cache::has('orbit:tasks:tick:started_at'))->toBeFalse();
        Cache::put('orbit:tasks:tick:started_at', '2026-10-06T06:00:00Z');
        expect($clock->lastStartedAt())->toBe('2026-10-07T06:00:00.000000Z');
    });
});

it('records and reads the start of the last tick', function (): void {
    $this->travelTo(Carbon::parse('2026-10-07T06:00:00Z'));
    $clock = new TaskTickClock;

    expect($clock->lastStartedAt())->toBeNull();

    $clock->record();

    expect($clock->lastStartedAt())->toBe('2026-10-07T06:00:00.000000Z');
});

it('records the version of the code that ran the tick beside its start', function (): void {
    $this->travelTo(Carbon::parse('2026-10-07T06:00:00Z'));
    config(['app.version' => str_repeat('a', 40)]);
    $clock = new TaskTickClock;

    expect($clock->lastStarted())->toBeNull();

    $clock->record();

    expect($clock->lastStarted())->toBe(['started_at' => '2026-10-07T06:00:00.000000Z', 'version' => str_repeat('a', 40)]);

    // A checkout has no REVISION, so without APP_VERSION the Gateway reports `dev`.
    config(['app.version' => '']);
    $clock->record();

    expect($clock->lastStarted())->toBe(['started_at' => '2026-10-07T06:00:00.000000Z', 'version' => 'dev']);
});

it('reports a cache that cannot be written or read without failing the tick or the status', function (): void {
    Exceptions::fake();
    Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('The cache could not be written.'));
    Cache::shouldReceive('get')->twice()->andThrow(new RuntimeException('The cache could not be read.'));
    $clock = new TaskTickClock;

    $clock->record();

    expect($clock->lastStartedAt())->toBeNull()
        ->and($clock->lastStarted())->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});
