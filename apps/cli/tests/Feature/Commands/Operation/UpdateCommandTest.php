<?php

declare(strict_types=1);

use App\Services\Updates\RunsLocalUpdate;

final class UpdateCommandFakeUpdater implements RunsLocalUpdate
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

beforeEach(function (): void {
    $this->updater = new UpdateCommandFakeUpdater;

    app()->instance(RunsLocalUpdate::class, $this->updater);
});

describe('update', function (): void {
    it('returns a JSON success envelope with the local update result', function (): void {
        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data']['update']['scope'])->toBe('local')
            ->and($decoded['success']['data']['update']['target'])->toBe('local')
            ->and($decoded['success']['data']['update']['status'])->toBe('completed')
            ->and($decoded['success']['data']['update']['steps'])->toBe([
                ['name' => 'pull_source', 'status' => 'completed'],
                ['name' => 'install_dependencies', 'status' => 'completed'],
                ['name' => 'run_migrations', 'status' => 'completed'],
            ]);
    });

    it('runs local update steps in order', function (): void {
        [$exitCode] = runCommand($this, 'update', ['--json' => true]);

        expect($exitCode)->toBe(0)
            ->and($this->updater->calls)->toBe([
                'pull_source',
                'install_dependencies',
                'run_migrations',
            ]);
    });

    it('renders a human progress tree before reporting success', function (): void {
        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Updating Orbit')
            ->and($output)->toContain('Pull source')
            ->and($output)->toContain('Install dependencies')
            ->and($output)->toContain('Run migrations')
            ->and($output)->toContain('Updated local Orbit checkout.')
            ->and($output)->not->toContain('"success"');
    });

    it('renders local_update_failed with failed step metadata and captured output', function (): void {
        $this->updater->results['install_dependencies'] = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'orbit-runtime container unavailable',
        ];

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('local_update_failed')
            ->and($decoded['error']['message'])->toBe('Failed to update local Orbit checkout.')
            ->and($decoded['error']['meta'])->toBe(['failed_step' => 'install_dependencies'])
            ->and($decoded['error']['data'])->toBe(['output' => 'orbit-runtime container unavailable']);
    });

    it('renders local_checkout_unavailable with the checkout path when git cannot access the checkout', function (): void {
        $this->updater->results['pull_source'] = [
            'successful' => false,
            'exit_code' => 128,
            'output' => 'fatal: not a git repository',
        ];

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('local_checkout_unavailable')
            ->and($decoded['error']['message'])->toBe('Local Orbit checkout cannot be updated.')
            ->and($decoded['error']['meta']['path'])->toBe(dirname(base_path(), 2));
    });

    it('renders failure prose and captured output in human mode', function (): void {
        $this->updater->results['run_migrations'] = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'SQLSTATE migration failed',
        ];

        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Failed to update local Orbit checkout.')
            ->and($output)->toContain('SQLSTATE migration failed')
            ->and($output)->not->toContain('"error"');
    });
});
