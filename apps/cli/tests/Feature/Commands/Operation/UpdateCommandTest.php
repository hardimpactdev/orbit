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
            ]);
    });

    it('runs only the local binary update step', function (): void {
        [$exitCode] = runCommand($this, 'update', ['--json' => true]);

        expect($exitCode)->toBe(0)
            ->and($this->updater->calls)->toBe([
                'pull_source',
            ]);
    });

    it('renders a human progress tree before reporting success', function (): void {
        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Updating Orbit')
            ->and($output)->toContain('Download binary')
            ->and($output)->toContain('Updated local Orbit checkout.')
            ->and($output)->not->toContain('"success"');
    });

    it('renders local_checkout_unavailable when the install root does not exist', function (): void {
        $previous = getenv('ORBIT_INSTALL_PATH');
        putenv('ORBIT_INSTALL_PATH=/nonexistent/orbit-update-cmd-test');

        try {
            [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('local_checkout_unavailable')
                ->and($decoded['error']['message'])->toBe('Local Orbit checkout cannot be updated.')
                ->and($decoded['error']['meta'])->toBe(['path' => '/nonexistent/orbit-update-cmd-test'])
                ->and($this->updater->calls)->toBe([]);
        } finally {
            $previous === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previous}");
        }
    });

    it('renders local_update_failed with binary update output', function (): void {
        $this->updater->results['pull_source'] = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'binary download failed',
        ];

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('local_update_failed')
            ->and($decoded['error']['message'])->toBe('Failed to update local Orbit checkout.')
            ->and($decoded['error']['meta'])->toBe(['failed_step' => 'pull_source'])
            ->and($decoded['error']['data'])->toBe(['output' => 'binary download failed']);
    });

    it('keeps the install path out of binary download failures', function (): void {
        $previous = getenv('ORBIT_INSTALL_PATH');

        if (! is_dir('/tmp/orbit-update-cmd-test')) {
            mkdir('/tmp/orbit-update-cmd-test', 0755, true);
        }

        putenv('ORBIT_INSTALL_PATH=/tmp/orbit-update-cmd-test');

        try {
            $this->updater->results['pull_source'] = [
                'successful' => false,
                'exit_code' => 6,
                'output' => 'curl: (6) Could not resolve host',
            ];

            [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)
                ->and($decoded['error']['code'])->toBe('local_update_failed')
                ->and($decoded['error']['message'])->toBe('Failed to update local Orbit checkout.')
                ->and($decoded['error']['meta'])->toBe(['failed_step' => 'pull_source'])
                ->and($decoded['error']['data'])->toBe(['output' => 'curl: (6) Could not resolve host']);
        } finally {
            $previous === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previous}");
        }
    });

    it('renders failure prose and captured output in human mode', function (): void {
        $this->updater->results['pull_source'] = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'binary verify failed',
        ];

        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Failed to update local Orbit checkout.')
            ->and($output)->toContain('binary verify failed')
            ->and($output)->not->toContain('"error"');
    });
});
