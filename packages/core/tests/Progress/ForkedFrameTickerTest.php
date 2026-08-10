<?php

declare(strict_types=1);

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

it('arms the child stop handler before an immediate stop signal can be delivered', function (): void {
    if (
        ! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')
        || ! function_exists('pcntl_signal')
        || ! function_exists('pcntl_async_signals')
        || ! function_exists('pcntl_sigprocmask')
    ) {
        $this->markTestSkipped('pcntl signal masking is required to verify ticker startup.');
    }

    $sandboxPid = pcntl_fork();

    if ($sandboxPid === -1) {
        $this->markTestSkipped('pcntl_fork is required to verify ticker startup.');
    }

    if ($sandboxPid === 0) {
        $selfPid = getmypid();

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($selfPid): never {
            if (getmypid() !== $selfPid) {
                posix_kill($selfPid, SIGTERM);
            }

            exit(143);
        });

        for ($attempt = 0; $attempt < 64; $attempt++) {
            $ticker = new ForkedFrameTicker(1_000_000);
            $ticker->start(static function (): void {});
            $ticker->stop();
        }

        exit(0);
    }

    pcntl_waitpid($sandboxPid, $status);

    expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
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
