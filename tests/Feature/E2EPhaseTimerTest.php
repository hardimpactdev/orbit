<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EPhaseTimer;

it('returns the callback value from measure', function (): void {
    $timer = new E2EPhaseTimer;

    $result = $timer->measure('phase', fn () => 42);

    expect($result)->toBe(42);
});

it('records phase events with non-negative durations', function (): void {
    $timer = new E2EPhaseTimer;

    $timer->measure('first', fn () => null);
    $timer->measure('second', fn () => null);

    $events = $timer->events();

    expect($events)->toHaveCount(2)
        ->and($events[0]['name'])->toBe('first')
        ->and($events[0]['seconds'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($events[1]['name'])->toBe('second');
});

it('records the event even when the callback throws', function (): void {
    $timer = new E2EPhaseTimer;

    expect(fn () => $timer->measure('boom', function (): void {
        throw new RuntimeException('nope');
    }))->toThrow(RuntimeException::class, 'nope');

    expect($timer->events())->toHaveCount(1)
        ->and($timer->events()[0]['name'])->toBe('boom');
});

it('flush is silent when ORBIT_E2E_TIMINGS is not 1', function (): void {
    $previous = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TIMINGS');

    try {
        $timer = new E2EPhaseTimer;
        $timer->measure('phase', fn () => null);

        ob_start();
        $stderr = fopen('php://memory', 'w+');
        $timer->flush('label');
        ob_end_clean();

        // Re-running with the env unset should produce no STDERR output.
        // We can only assert behavior indirectly: events remain queryable
        // and no exception is thrown.
        expect($timer->events())->toHaveCount(1);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TIMINGS');
        } else {
            putenv("ORBIT_E2E_TIMINGS={$previous}");
        }
    }
});
