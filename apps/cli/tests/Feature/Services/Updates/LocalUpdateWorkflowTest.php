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
     * @return array{successful: bool, exit_code: int, output: string, staged_path: string|null, version: string|null}
     */
    public function downloadBinary(string $expectedVersion = ''): array
    {
        $this->calls[] = 'download';

        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => '',
            'staged_path' => '/tmp/staged-orbit',
            'version' => '1.2.3',
        ];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string, skipped: bool}
     */
    public function replaceBinary(string $stagedPath, string $version, ?string $releasedAt = null): array
    {
        $this->calls[] = 'replace';

        return ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => false];
    }

    /**
     * @return array{issues: int|null}
     */
    public function runDoctor(): array
    {
        $this->calls[] = 'doctor';

        return ['issues' => 0];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        $this->calls[] = 'install_dependencies';

        return $this->results['install_dependencies'];
    }

    public function ensureShellIntegrations(): array
    {
        $this->calls[] = 'ensure_shell_integrations';

        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function verifyCurrentInstallation(string $expectedVersion): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
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
    beforeEach(function (): void {
        $this->installRoot = sys_get_temp_dir().'/orbit-workflow-test-install';

        if (! is_dir($this->installRoot)) {
            mkdir($this->installRoot, 0755, true);
        }

        $this->previousInstallPath = getenv('ORBIT_INSTALL_PATH');
        putenv("ORBIT_INSTALL_PATH={$this->installRoot}");
    });

    afterEach(function (): void {
        $this->previousInstallPath === false
            ? putenv('ORBIT_INSTALL_PATH')
            : putenv("ORBIT_INSTALL_PATH={$this->previousInstallPath}");
    });

    it('returns installation_unavailable without running steps when the install root is missing', function (): void {
        putenv('ORBIT_INSTALL_PATH=/nonexistent/orbit-workflow-test-install');

        $updater = new LocalUpdateWorkflowFakeUpdater;

        $result = new LocalUpdateWorkflow($updater, new CheckoutPathResolver)->run();

        expect($result->status)
            ->toBe(LocalUpdateResult::STATUS_INSTALLATION_UNAVAILABLE)
            ->and($result->installationPath)
            ->toBe('/nonexistent/orbit-workflow-test-install')
            ->and($updater->calls)
            ->toBe([]);
    });

    it('runs the local binary update step and returns a completed result', function (): void {
        $updater = new LocalUpdateWorkflowFakeUpdater;

        $result = new LocalUpdateWorkflow($updater, new CheckoutPathResolver)->run();

        expect($result->status)
            ->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->stepResults)
            ->toBe([
                'pull_source' => 'completed',
            ])
            ->and($updater->calls)
            ->toBe([
                'pull_source',
            ]);
    });

    it('returns failed step metadata when the binary update fails', function (): void {
        $updater = new LocalUpdateWorkflowFakeUpdater;
        $updater->results['pull_source'] = [
            'successful' => false,
            'exit_code' => 6,
            'output' => 'curl: (6) Could not resolve host',
        ];

        $result = new LocalUpdateWorkflow($updater, new CheckoutPathResolver)->run();

        expect($result->status)
            ->toBe(LocalUpdateResult::STATUS_FAILED)
            ->and($result->failedStep)
            ->toBe('pull_source')
            ->and($result->output)
            ->toBe('curl: (6) Could not resolve host')
            ->and($updater->calls)
            ->toBe(['pull_source']);
    });

    it('does not run gateway dependency or migration steps during local update', function (): void {
        $updater = new LocalUpdateWorkflowFakeUpdater;
        $updater->results['install_dependencies'] = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'orbit-gateway container unavailable',
        ];

        $result = new LocalUpdateWorkflow($updater, new CheckoutPathResolver)->run();

        expect($result->status)
            ->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->failedStep)
            ->toBeNull()
            ->and($result->output)
            ->toBe('')
            ->and($updater->calls)
            ->toBe([
                'pull_source',
            ]);
    });
});
