<?php

declare(strict_types=1);

use Orbit\Core\Progress\ForkedChildProcess;
use Orbit\Core\Progress\ForkedFrameTicker;

it('registers an idle callback for stream polling even when pcntl fork support exists', function (): void {
    $tickCount = 0;
    $ticker = new ForkedFrameTicker;
    $ticker->start(function () use (&$tickCount): void {
        $tickCount++;
    });

    expect(ForkedFrameTicker::hasIdleCallback())->toBeTrue();

    ForkedFrameTicker::invokeIdleCallback();
    ForkedFrameTicker::invokeIdleCallback();

    $ticker->stop();

    expect($tickCount)->toBe(1)->and(ForkedFrameTicker::hasIdleCallback())->toBeFalse();
});

it('invokes tick callbacks in the parent process while work is blocked', function (): void {
    if (
        ! function_exists('pcntl_fork')
        || ! function_exists('posix_kill')
        || ! function_exists('pcntl_signal')
        || ! function_exists('pcntl_async_signals')
    ) {
        $this->markTestSkipped(
            'pcntl_fork, posix_kill, pcntl_signal, and pcntl_async_signals are required to observe parent-process ticker callbacks.',
        );
    }

    $parentPid = getmypid();
    $callbackPids = [];
    $tickCount = 0;

    $ticker = new ForkedFrameTicker(40_000);
    $ticker->start(function () use (&$callbackPids, &$tickCount): void {
        $callbackPids[] = getmypid();
        $tickCount++;
    });

    $deadline = microtime(true) + 0.14;

    while (microtime(true) < $deadline) {
        usleep(50_000);
    }

    $ticker->stop();

    expect($tickCount)
        ->toBeGreaterThanOrEqual(2)
        ->and(array_values(array_unique($callbackPids)))
        ->toBe([$parentPid]);
});

it('throttles duplicate idle invocations within one interval window', function (): void {
    $tickCount = 0;
    $ticker = new ForkedFrameTicker(100_000);
    $ticker->start(function () use (&$tickCount): void {
        $tickCount++;
    });

    ForkedFrameTicker::invokeIdleCallback();
    ForkedFrameTicker::invokeIdleCallback();
    ForkedFrameTicker::invokeIdleCallback();

    $ticker->stop();

    expect($tickCount)->toBe(1);
});

it('can register an idle callback without forking a signal child', function (): void {
    $tickCount = 0;

    ForkedFrameTicker::withoutForking(function () use (&$tickCount): void {
        $ticker = new ForkedFrameTicker(100_000);
        $ticker->start(function () use (&$tickCount): void {
            $tickCount++;
        });

        expect($tickCount)->toBe(0)->and(ForkedFrameTicker::hasIdleCallback())->toBeTrue();

        ForkedFrameTicker::invokeIdleCallback();
        $ticker->stop();
    });

    expect($tickCount)->toBe(1)->and(ForkedFrameTicker::hasIdleCallback())->toBeFalse();
});

it('does not signal a pid that is no longer its child', function (): void {
    if (
        ! function_exists('pcntl_async_signals')
        || ! function_exists('pcntl_signal')
        || ! function_exists('pcntl_signal_get_handler')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')
    ) {
        $this->markTestSkipped('PCNTL and POSIX signal support are required to guard a reused ticker pid.');
    }

    $receivedSignal = false;
    $previousHandler = pcntl_signal_get_handler(SIGTERM);
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$receivedSignal): void {
        $receivedSignal = true;
    });

    try {
        $pid = getmypid();

        expect($pid)->toBeInt();

        new ForkedChildProcess($pid)->stop();
    } finally {
        pcntl_signal(SIGTERM, $previousHandler);
    }

    expect($receivedSignal)->toBeFalse();
});
