<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;
use App\Services\Updates\LocalUpdateResult;
use App\Services\Updates\LocalUpdateWorkflow;
use App\Services\Updates\RunsLocalUpdate;

final class LocalUpdateWorkflowFakeUpdater implements RunsLocalUpdate
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, array{successful: bool, exit_code: int, output: string}> */
    public array $results = [
        'pull_source' => ['successful' => true, 'exit_code' => 0, 'output' => ''],
        'install_dependencies' => ['successful' => true, 'exit_code' => 0, 'output' => ''],
        'run_migrations' => ['successful' => true, 'exit_code' => 0, 'output' => ''],
    ];

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        $this->calls[] = 'pull_source';

        return $this->results['pull_source'];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        $this->calls[] = 'install_dependencies';

        return $this->results['install_dependencies'];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array
    {
        $this->calls[] = 'run_migrations';

        return $this->results['run_migrations'];
    }
}

describe('LocalUpdateWorkflow', function (): void {
    it('runs local update steps in order and returns a completed result', function (): void {
        $updater = new LocalUpdateWorkflowFakeUpdater;

        $result = (new LocalUpdateWorkflow($updater, new CheckoutPathResolver))->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->stepResults)->toBe([
                'pull_source' => 'completed',
                'install_dependencies' => 'completed',
                'run_migrations' => 'completed',
            ])
            ->and($updater->calls)->toBe([
                'pull_source',
                'install_dependencies',
                'run_migrations',
            ]);
    });

    it('returns checkout unavailable when git cannot access the checkout', function (): void {
        $updater = new LocalUpdateWorkflowFakeUpdater;
        $updater->results['pull_source'] = [
            'successful' => false,
            'exit_code' => 128,
            'output' => 'fatal: not a git repository',
        ];

        $result = (new LocalUpdateWorkflow($updater, new CheckoutPathResolver))->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE)
            ->and($result->checkoutPath)->toBe(dirname(base_path(), 2))
            ->and($updater->calls)->toBe(['pull_source']);
    });

    it('returns failed with step metadata when a later step fails', function (): void {
        $updater = new LocalUpdateWorkflowFakeUpdater;
        $updater->results['install_dependencies'] = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'orbit-runtime container unavailable',
        ];

        $result = (new LocalUpdateWorkflow($updater, new CheckoutPathResolver))->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_FAILED)
            ->and($result->failedStep)->toBe('install_dependencies')
            ->and($result->output)->toBe('orbit-runtime container unavailable')
            ->and($updater->calls)->toBe([
                'pull_source',
                'install_dependencies',
            ]);
    });
});
