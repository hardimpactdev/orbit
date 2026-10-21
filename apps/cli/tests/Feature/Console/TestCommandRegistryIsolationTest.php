<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\CachedState;
use Symfony\Component\Process\Process;
use Tests\Support\CommandRegistrySnapshot;

pest()->group('subprocess');

it('rebuilds fresh commands from cached classes with the complete uncached registry', function (): void {
    $baseline = CachedState::$cachedConfig;
    $command = app(Kernel::class)->all()['node:show'];
    $command->setDescription('A command mutation that must not survive a fresh application.');

    $this->refreshApplication();

    expect(config('commands.paths'))->toBe([])
        ->and(app(Kernel::class)->all()['node:show'])->not->toBe($command)
        ->and(CachedState::$cachedConfig)->toBe($baseline);

    $process = new Process([
        PHP_BINARY, base_path('tests/Fixtures/Console/command-registry.php'),
        $_SERVER['PHP_SELF'], $_SERVER['SCRIPT_FILENAME'],
    ]);
    $process->mustRun();

    expect(CommandRegistrySnapshot::capture(app(Kernel::class)))
        ->toBe(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
});
