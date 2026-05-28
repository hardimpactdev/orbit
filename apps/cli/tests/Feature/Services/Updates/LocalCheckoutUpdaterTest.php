<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;
use App\Services\Updates\LocalCheckoutUpdater;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

describe('LocalCheckoutUpdater', function (): void {
    it('pulls the current checkout with fast-forward-only semantics', function (): void {
        Process::fake([
            'git pull --ff-only' => Process::result(output: 'Already up to date.', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result)->toBe([
            'successful' => true,
            'exit_code' => 0,
            'output' => 'Already up to date.',
        ]);

        Process::assertRan(fn (PendingProcess $process, ProcessResult $processResult): bool => $process->path === dirname(base_path(), 2)
            && $process->command === 'git pull --ff-only'
            && $processResult->successful());
    });

    it('installs dependencies inside orbit-runtime', function (): void {
        Process::fake([
            '*' => Process::result(output: 'Installing dependencies', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->installDependencies();

        expect($result['successful'])->toBeTrue()
            ->and($result['output'])->toBe('Installing dependencies');

        Process::assertRan(fn (PendingProcess $process): bool => $process->path === dirname(base_path(), 2)
            && $process->command === [
                'docker',
                'exec',
                'orbit-runtime',
                'composer',
                '--working-dir=apps/gateway',
                'install',
                '--no-interaction',
            ]);
    });

    it('runs local migrations inside orbit-runtime with force', function (): void {
        Process::fake([
            '*' => Process::result(output: 'Migrated', exitCode: 0),
        ]);
        Process::preventStrayProcesses();

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->runMigrations();

        expect($result['successful'])->toBeTrue()
            ->and($result['output'])->toBe('Migrated');

        Process::assertRan(fn (PendingProcess $process): bool => $process->path === dirname(base_path(), 2)
            && $process->command === [
                'docker',
                'exec',
                'orbit-runtime',
                'php',
                'apps/gateway/artisan',
                'migrate',
                '--force',
            ]);
    });

    it('uses stderr output when a step fails', function (): void {
        Process::fake([
            'git pull --ff-only' => Process::result(
                output: 'stdout message',
                errorOutput: 'fatal: not a git repository',
                exitCode: 128,
            ),
        ]);
        Process::preventStrayProcesses();

        $result = (new LocalCheckoutUpdater(new CheckoutPathResolver))->pullSource();

        expect($result)->toBe([
            'successful' => false,
            'exit_code' => 128,
            'output' => 'fatal: not a git repository',
        ]);
    });
});
