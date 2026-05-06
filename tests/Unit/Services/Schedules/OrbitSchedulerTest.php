<?php

declare(strict_types=1);

use App\Services\Schedules\OrbitScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('runs a scheduler tick without schedule state until schema slices land', function (): void {
    $startedAt = CarbonImmutable::parse('2026-05-06 12:34:00', 'UTC');

    $result = app(OrbitScheduler::class)->tick($startedAt);

    expect($result->startedAt->equalTo($startedAt))->toBeTrue()
        ->and($result->dueSchedules)->toBe(0)
        ->and($result->executedSchedules)->toBe(0)
        ->and($result->finishedAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('aligns daemon sleeps to the next wall-clock minute', function (): void {
    $scheduler = app(OrbitScheduler::class);

    expect($scheduler->secondsUntilNextMinute(CarbonImmutable::parse('2026-05-06 12:34:45', 'UTC')))->toBe(15)
        ->and($scheduler->secondsUntilNextMinute(CarbonImmutable::parse('2026-05-06 12:34:00', 'UTC')))->toBe(60);
});
