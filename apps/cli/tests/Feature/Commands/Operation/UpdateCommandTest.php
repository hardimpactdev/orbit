<?php

declare(strict_types=1);

use App\Services\Updates\RunsLocalUpdate;
use Illuminate\Support\Facades\Http;

final class UpdateCommandFakeUpdater implements RunsLocalUpdate
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array{successful: bool, exit_code: int, output: string, staged_path: string|null, version: string|null} */
    public array $downloadResult = [
        'successful' => true,
        'exit_code' => 0,
        'output' => '',
        'staged_path' => '/tmp/staged-orbit',
        'version' => '0.1.131',
    ];

    /** @var array{successful: bool, exit_code: int, output: string, skipped: bool} */
    public array $replaceResult = [
        'successful' => true,
        'exit_code' => 0,
        'output' => '',
        'skipped' => false,
    ];

    /** @var array{issues: int|null} */
    public array $doctorResult = ['issues' => 0];

    public function pullSource(): array
    {
        $this->calls[] = 'pull_source';

        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function downloadBinary(): array
    {
        $this->calls[] = 'download';

        return $this->downloadResult;
    }

    public function replaceBinary(string $stagedPath, string $version): array
    {
        $this->calls[] = 'replace';

        return $this->replaceResult;
    }

    public function runDoctor(): array
    {
        $this->calls[] = 'doctor';

        return $this->doctorResult;
    }

    public function installDependencies(): array
    {
        $this->calls[] = 'install_dependencies';

        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function runMigrations(): array
    {
        $this->calls[] = 'run_migrations';

        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }
}

/**
 * @return array<string, mixed>
 */
function updateCommandManifest(string $version): array
{
    return ['schema_version' => 1, 'version' => $version, 'released_at' => '2026-06-18T00:00:00Z'];
}

function fakeUpdateLatest(string $latest): void
{
    Http::fake([
        'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(updateCommandManifest($latest)),
        'https://github.com/hardimpactdev/orbit/releases/download/*' => Http::response(updateCommandManifest($latest)),
        'https://api.github.com/*' => Http::response([], 404),
    ]);
}

beforeEach(function (): void {
    $this->updater = new UpdateCommandFakeUpdater;

    app()->instance(RunsLocalUpdate::class, $this->updater);

    $this->installRoot = sys_get_temp_dir().'/orbit-update-cmd-root';

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

describe('update', function (): void {
    it('proceeds and returns a JSON success envelope with the version transition', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data']['update']['scope'])->toBe('local')
            ->and($decoded['success']['data']['update']['target'])->toBe('local')
            ->and($decoded['success']['data']['update']['status'])->toBe('completed')
            ->and($decoded['success']['data']['update']['from_version'])->toBe('0.1.130')
            ->and($decoded['success']['data']['update']['to_version'])->toBe('0.1.131')
            ->and($decoded['success']['data']['update']['latest_version'])->toBe('0.1.131')
            ->and($decoded['success']['data']['update']['doctor_issues'])->toBe(0)
            ->and($decoded['success']['data']['update']['steps'])->toBe([
                ['name' => 'check', 'status' => 'completed'],
                ['name' => 'download', 'status' => 'completed'],
                ['name' => 'replace', 'status' => 'completed'],
                ['name' => 'doctor', 'status' => 'completed'],
            ])
            ->and($this->updater->calls)->toBe(['download', 'replace', 'doctor']);
    });

    it('renders the multi-step human progress tree and the version-transition footer', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Updating Orbit')
            ->and($output)->toContain('Checking for updates')
            ->and($output)->toContain('Downloading binary')
            ->and($output)->toContain('Replacing binary')
            ->and($output)->toContain('Running doctor')
            ->and($output)->toContain('Success: Updated from 0.1.130 to 0.1.131')
            ->and($output)->not->toContain('"success"');
    });

    it('returns a JSON skip envelope when already on the latest release', function (): void {
        config()->set('app.version', '0.1.131');
        fakeUpdateLatest('0.1.131');

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['update']['status'])->toBe('skipped_already')
            ->and($decoded['success']['data']['update']['latest_version'])->toBe('0.1.131')
            ->and($decoded['success']['data']['update']['steps'])->toBe([
                ['name' => 'check', 'status' => 'skipped'],
            ])
            ->and($this->updater->calls)->toBe([]);
    });

    it('renders the already-installed skip footer in human mode', function (): void {
        config()->set('app.version', '0.1.131');
        fakeUpdateLatest('0.1.131');

        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Checking for updates')
            ->and($output)->toContain('Skipped: 0.1.131 is already installed')
            ->and($output)->not->toContain('Downloading binary')
            ->and($output)->not->toContain('"success"');
    });

    it('returns a JSON skip envelope when the gateway is still behind', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.130']]);

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['update']['status'])->toBe('skipped_gateway_behind')
            ->and($decoded['success']['data']['update']['latest_version'])->toBe('0.1.131')
            ->and($decoded['success']['data']['update']['steps'])->toBe([
                ['name' => 'check', 'status' => 'completed'],
            ])
            ->and($this->updater->calls)->toBe([]);
    });

    it('renders the gateway-first skip footer in human mode', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.130']]);

        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Checking for updates')
            ->and($output)->toContain('Done: new version available, 0.1.131')
            ->and($output)->toContain('Skipped: please update your gateway first')
            ->and($output)->not->toContain('Downloading binary')
            ->and($output)->not->toContain('"success"');
    });

    it('returns a JSON failure envelope when the version check cannot resolve the latest release', function (): void {
        config()->set('app.version', '0.1.130');
        Http::fake([
            'https://github.com/*' => Http::response([], 404),
            'https://api.github.com/*' => Http::response([], 404),
        ]);

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('local_update_check_failed')
            ->and($decoded['error']['meta'])->toBe(['failed_step' => 'check'])
            ->and($this->updater->calls)->toBe([]);
    });

    it('renders local_checkout_unavailable when the install root does not exist', function (): void {
        putenv('ORBIT_INSTALL_PATH=/nonexistent/orbit-update-cmd-test');

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('local_checkout_unavailable')
            ->and($decoded['error']['message'])->toBe('Local Orbit checkout cannot be updated.')
            ->and($decoded['error']['meta'])->toBe(['path' => '/nonexistent/orbit-update-cmd-test'])
            ->and($this->updater->calls)->toBe([]);
    });

    it('renders local_update_failed with binary update output', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $this->updater->downloadResult = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'binary download failed',
            'staged_path' => null,
            'version' => null,
        ];

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('local_update_failed')
            ->and($decoded['error']['message'])->toBe('Failed to update local Orbit checkout.')
            ->and($decoded['error']['meta'])->toBe(['failed_step' => 'download'])
            ->and($decoded['error']['data'])->toBe(['output' => 'binary download failed']);
    });

    it('renders failure prose and captured output in human mode', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $this->updater->downloadResult = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'binary verify failed',
            'staged_path' => null,
            'version' => null,
        ];

        [$exitCode, $output] = runCommand($this, 'update');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Failed to update local Orbit checkout.')
            ->and($output)->toContain('binary verify failed')
            ->and($output)->not->toContain('"error"');
    });

    it('surfaces the doctor issue count without failing the update in JSON mode', function (): void {
        config()->set('app.version', '0.1.130');
        fakeUpdateLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $this->updater->doctorResult = ['issues' => 2];

        [$exitCode, $output] = runCommand($this, 'update', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['update']['status'])->toBe('completed')
            ->and($decoded['success']['data']['update']['doctor_issues'])->toBe(2);
    });
});
