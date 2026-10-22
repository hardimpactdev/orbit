<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSubtaskRunner;
use Illuminate\Foundation\Application;
use Tests\Support\FakeTaskCheckRunner;

it('shares one subtask runner inside a scheduler graph but resolves fresh rebound collaborators for the next graph', function (): void {
    $runners = [];
    app()->resolving(TaskSubtaskRunner::class, function (TaskSubtaskRunner $runner) use (&$runners): void {
        $runners[] = $runner;
    });
    $firstChecks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $firstChecks);
    $first = app(TaskScheduler::class);

    expect($runners)->toHaveCount(1);
    $checks = new ReflectionProperty(TaskSubtaskRunner::class, 'checks');
    expect($checks->getValue($runners[0]))->toBe($firstChecks);

    $secondChecks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $secondChecks);
    $second = app(TaskScheduler::class);

    expect($second)->not->toBe($first);
    expect($runners)->toHaveCount(2);
    expect($runners[1])->not->toBe($runners[0]);
    expect($checks->getValue($runners[1]))->toBe($secondChecks);
    expect(app()->getBindings())->not->toHaveKey(TaskSubtaskRunner::class);
    expect(app(TaskSubtaskRunner::class))->not->toBe($runners[1]);
    expect(app(TaskSubtaskRunner::class))->not->toBe($runners[2]);
    expect($runners)->toHaveCount(4);
});

it('preserves a configured transient subtask-runner factory after scheduler construction', function (): void {
    $constructions = 0;
    $factory = function (Application $app) use (&$constructions): TaskSubtaskRunner {
        $constructions++;

        return $app->build(TaskSubtaskRunner::class);
    };
    app()->bind(TaskSubtaskRunner::class, $factory);

    app(TaskScheduler::class);

    expect($constructions)->toBe(1);
    expect(app()->getBindings()[TaskSubtaskRunner::class])->toBe(['concrete' => $factory, 'shared' => false]);
    $first = app(TaskSubtaskRunner::class);
    expect(app(TaskSubtaskRunner::class))->not->toBe($first);
    expect($constructions)->toBe(3);
});

it('retains caller-owned shared subtask runners after scheduler construction', function (bool $resolved): void {
    $runner = app(TaskSubtaskRunner::class);
    if ($resolved) {
        app()->instance(TaskSubtaskRunner::class, $runner);
    } else {
        app()->singleton(TaskSubtaskRunner::class, fn (): TaskSubtaskRunner => $runner);
    }

    app(TaskScheduler::class);

    expect(app(TaskSubtaskRunner::class))->toBe($runner);
})->with(['existing instance' => [true], 'configured singleton' => [false]]);

it('releases its temporary subtask runner when scheduler construction throws', function (): void {
    app()->resolving(TaskSubtaskRunner::class, function (): never {
        throw new RuntimeException('scheduler construction failed');
    });

    expect(fn () => app(TaskScheduler::class))->toThrow(RuntimeException::class, 'scheduler construction failed');
    expect(app()->isShared(TaskSubtaskRunner::class))->toBeFalse();
    expect(app()->getBindings())->not->toHaveKey(TaskSubtaskRunner::class);
});
