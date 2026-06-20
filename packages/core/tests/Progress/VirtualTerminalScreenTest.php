<?php

declare(strict_types=1);

use Orbit\Core\Progress\SpinnerTreeRenderer;
use Orbit\Core\Progress\VirtualTerminalScreen;

it('reconstructs alternating cyan spinner states for in-place progress repaints', function (): void {
    $screen = new VirtualTerminalScreen;
    $frames = SpinnerTreeRenderer::spinnerFrames();
    $openLine = '  '.$frames[0].'  gateway                  Replacing cli binary';
    $filledLine = '  '.$frames[1].'  gateway                  Replacing cli binary';
    $cursorRow = 10;
    $targetRow = 5;
    $up = $cursorRow - $targetRow;

    $screen->feed(str_repeat("\n", $cursorRow));

    foreach ([$openLine, $filledLine, $openLine] as $line) {
        $screen->feed("\e[{$up}A\e[2K\r{$line}\e[{$up}B\r");
    }

    $rows = $screen->rowsMatching('gateway', 'Replacing cli binary');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['spinner'])->toBe(VirtualTerminalScreen::SPINNER_CYAN_OPEN);

    $states = [];

    foreach ([$openLine, $filledLine, $openLine, $filledLine] as $line) {
        $screen->feed("\e[{$up}A\e[2K\r{$line}\e[{$up}B\r");
        $rows = $screen->rowsMatching('gateway', 'Replacing cli binary');
        $states[] = $rows[0]['spinner'] ?? null;
    }

    expect(array_values(array_unique(array_filter($states))))->toBe([
        VirtualTerminalScreen::SPINNER_CYAN_OPEN,
        VirtualTerminalScreen::SPINNER_CYAN_FILLED,
    ]);
});

it('collects spinner state transitions while replaying transcript chunks', function (): void {
    $screen = new VirtualTerminalScreen;
    $frames = SpinnerTreeRenderer::spinnerFrames();
    $openLine = '  '.$frames[0].'  gateway                  Replacing cli binary';
    $filledLine = '  '.$frames[1].'  gateway                  Replacing cli binary';
    $cursorRow = 10;
    $targetRow = 5;
    $up = $cursorRow - $targetRow;
    $lastObservation = null;
    $observed = [];

    $screen->feed(str_repeat("\n", $cursorRow));

    foreach ([$openLine, $filledLine, $openLine, $filledLine] as $line) {
        foreach ($screen->feedAndCollectMatchingSpinnerStates(
            "\e[{$up}A\e[2K\r{$line}\e[{$up}B\r",
            'gateway',
            'Replacing cli binary',
            $lastObservation,
        ) as $observation) {
            $observed[] = $observation;
        }
    }

    expect($observed)->toHaveCount(4)
        ->and(array_values(array_unique(array_column($observed, 'row'))))->toBe([$targetRow])
        ->and(array_column($observed, 'spinner'))->toBe([
            VirtualTerminalScreen::SPINNER_CYAN_OPEN,
            VirtualTerminalScreen::SPINNER_CYAN_FILLED,
            VirtualTerminalScreen::SPINNER_CYAN_OPEN,
            VirtualTerminalScreen::SPINNER_CYAN_FILLED,
        ]);
});
