<?php

declare(strict_types=1);

use Orbit\Core\Progress\ForkedFrameTicker;

it('invokes tick callbacks in the parent process while work is blocked', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
        $this->markTestSkipped('pcntl_fork, posix_kill, pcntl_signal, and pcntl_async_signals are required to observe parent-process ticker callbacks.');
    }

    $parentPid = getmypid();
    $callbackPids = [];
    $tickCount = 0;

    $ticker = new ForkedFrameTicker(80_000);
    $ticker->start(function () use (&$callbackPids, &$tickCount): void {
        $callbackPids[] = getmypid();
        $tickCount++;
    });

    $deadline = microtime(true) + 0.7;

    while (microtime(true) < $deadline) {
        usleep(50_000);
    }

    $ticker->stop();

    expect($tickCount)->toBeGreaterThanOrEqual(2)
        ->and(array_values(array_unique($callbackPids)))->toBe([$parentPid]);
});
