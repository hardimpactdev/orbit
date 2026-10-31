<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskSchedule;
use Illuminate\Console\Scheduling\Schedule;

function task_schedule(bool $enabled): Schedule
{
    $extension = app(TaskExtensionState::class);
    $enabled ? $extension->enable() : $extension->disable();

    $schedule = new Schedule;
    app(TaskSchedule::class)->register($schedule);

    return $schedule;
}

it('schedules Task observation and problem filing without the retired tick', function (): void {
    config(['swamp.read_token' => 'reader.fixture-token']);
    $schedule = task_schedule(enabled: true);

    $commands = array_map(static fn ($event): string => $event->command ?? $event->description, $schedule->events());
    $file = collect($schedule->events())->first(
        static fn ($event): bool => str_contains((string) $event->command, 'problems:file'),
    );
    expect($schedule->events())->toHaveCount(3)
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:tick')))->toBeFalse()
        ->and(array_any($commands, static fn (string $command): bool => str_contains($command, 'tasks:collect-t3-metrics')))->toBeFalse()
        ->and($file)->not->toBeNull()
        ->and($file->expression)->toBe('0 * * * *')
        ->and($file->withoutOverlapping)->toBeTrue()
        ->and($file->expiresAt)->toBe(30)
        ->and(array_all($schedule->events(), static fn ($event): bool => $event->filtersPass(app())))->toBeTrue();
});

it('disables Task schedules while retaining the configured Swamp reader check', function (): void {
    config(['swamp.read_token' => 'reader.fixture-token']);
    $schedule = task_schedule(enabled: false);
    $reader = collect($schedule->events())->first(
        static fn ($event): bool => $event->description === 'gateway:swamp-reader:check'
            || str_contains((string) $event->command, 'gateway:swamp-reader:check'),
    );

    expect($schedule->events())->toHaveCount(3)
        ->and(array_all($schedule->events(), static fn ($event): bool => $event->filtersPass(app())))->toBeFalse()
        ->and($reader)->not->toBeNull()
        ->and($reader->filtersPass(app()))->toBeTrue();
});
