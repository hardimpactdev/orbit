<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\Updates\CheckoutPathResolver;
use App\Services\Updates\GatewayVersionProbe;
use App\Services\Updates\LocalUpdateResult;
use App\Services\Updates\LocalUpdateRunner;
use App\Services\Updates\RunsLocalUpdate;
use App\Services\Version\InstallMetadataStore;
use App\Services\Version\VersionInfoResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * In-memory updater double covering the split download/replace/doctor surface.
 */
final class RunnerFakeUpdater implements RunsLocalUpdate
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
        $this->calls[] = "replace:{$stagedPath}:{$version}";

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
function runnerManifest(string $version): array
{
    return ['schema_version' => 1, 'version' => $version, 'released_at' => '2026-06-18T00:00:00Z'];
}

/**
 * Fake the latest-release lookup used by VersionInfoResolver.
 */
function fakeRunnerLatest(string $latest): void
{
    Http::fake([
        'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(runnerManifest($latest)),
        'https://github.com/hardimpactdev/orbit/releases/download/*' => Http::response(runnerManifest($latest)),
        'https://api.github.com/*' => Http::response([], 404),
    ]);
}

/**
 * Fake an unreachable / empty release source so the latest version is unresolvable.
 */
function fakeRunnerNoRelease(): void
{
    Http::fake([
        'https://github.com/*' => Http::response([], 404),
        'https://api.github.com/*' => Http::response([], 404),
    ]);
}

function makeRunner(RunnerFakeUpdater $updater): LocalUpdateRunner
{
    return new LocalUpdateRunner(
        $updater,
        new CheckoutPathResolver,
        new VersionInfoResolver(new InstallMetadataStore),
        new GatewayVersionProbe(app(GatewayApiClient::class)),
    );
}

describe('LocalUpdateRunner', function (): void {
    beforeEach(function (): void {
        $this->installRoot = sys_get_temp_dir().'/orbit-runner-test-install';

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

    it('returns checkout_unavailable without running steps when the install root is missing', function (): void {
        putenv('ORBIT_INSTALL_PATH=/nonexistent/orbit-runner-test-install');

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE)
            ->and($result->checkoutPath)->toBe('/nonexistent/orbit-runner-test-install')
            ->and($updater->calls)->toBe([]);
    });

    it('fails the check when the latest release cannot be resolved', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerNoRelease();

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_CHECK_FAILED)
            ->and($result->failedStep)->toBe('check')
            ->and($updater->calls)->toBe([])
            ->and($result->stepResults)->toBe(['check' => 'failed']);
    });

    it('skips when the installed version already equals the latest release', function (): void {
        config()->set('app.version', '0.1.131');
        fakeRunnerLatest('0.1.131');

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_SKIPPED_ALREADY)
            ->and($result->latestVersion)->toBe('0.1.131')
            ->and($result->fromVersion)->toBe('0.1.131')
            ->and($updater->calls)->toBe([])
            ->and($result->stepResults)->toBe(['check' => 'skipped']);
    });

    it('skips with gateway-behind when the gateway is older than the latest release', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.130']]);

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_SKIPPED_GATEWAY_BEHIND)
            ->and($result->latestVersion)->toBe('0.1.131')
            ->and($updater->calls)->toBe([])
            ->and($result->stepResults)->toBe(['check' => 'completed']);
    });

    it('emits a blink-only check step without a textual in-progress message', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $steps = [];
        makeRunner(new RunnerFakeUpdater)->run(function (string $step, string $status, ?string $message) use (&$steps): void {
            $steps[] = [$step, $status, $message];
        });

        expect($steps[0])->toBe(['check', 'start', null]);
    });

    it('proceeds when the gateway is already on the latest release', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->fromVersion)->toBe('0.1.130')
            ->and($result->toVersion)->toBe('0.1.131')
            ->and($result->latestVersion)->toBe('0.1.131')
            ->and($result->doctorIssues)->toBe(0)
            ->and($updater->calls)->toBe([
                'download',
                'replace:/tmp/staged-orbit:0.1.131',
                'doctor',
            ])
            ->and($result->stepResults)->toBe([
                'check' => 'completed',
                'download' => 'completed',
                'replace' => 'completed',
                'doctor' => 'completed',
            ]);
    });

    it('proceeds without a ceiling when no gateway is configured', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeNoGatewayConfig(base_path('tests/.tmp-local-update-runner-empty-config.json'));

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->toVersion)->toBe('0.1.131')
            ->and($updater->calls)->toBe([
                'download',
                'replace:/tmp/staged-orbit:0.1.131',
                'doctor',
            ]);
    });

    it('proceeds without a ceiling when the gateway is unreachable', function (): void {
        config()->set('app.version', '0.1.130');
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        // Manifest resolves; the gateway status probe times out (unknown ceiling).
        Http::fake([
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json' => Http::response(runnerManifest('0.1.131')),
            'https://github.com/hardimpactdev/orbit/releases/download/*' => Http::response(runnerManifest('0.1.131')),
            'https://api.github.com/*' => Http::response([], 404),
            'https://gateway.test/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $updater = new RunnerFakeUpdater;

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->toVersion)->toBe('0.1.131')
            ->and($updater->calls)->toBe([
                'download',
                'replace:/tmp/staged-orbit:0.1.131',
                'doctor',
            ]);
    });

    it('reports the doctor issue count without failing the update', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $updater = new RunnerFakeUpdater;
        $updater->doctorResult = ['issues' => 3];

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->doctorIssues)->toBe(3)
            ->and($result->stepResults)->toBe([
                'check' => 'completed',
                'download' => 'completed',
                'replace' => 'completed',
                'doctor' => 'completed',
            ]);
    });

    it('records the replace step as completed when the low-level move was skipped', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $updater = new RunnerFakeUpdater;
        $updater->replaceResult = ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => true];

        $steps = [];
        $result = makeRunner($updater)->run(function (string $step, string $status, ?string $message) use (&$steps): void {
            $steps[] = [$step, $status, $message];
        });

        expect($result->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($result->stepResults['replace'])->toBe('completed')
            ->and(collect($steps)->first(fn (array $step): bool => $step[0] === 'replace' && $step[1] === 'done'))
            ->toBe(['replace', 'done', 'Done']);
    });

    it('stops at check as already installed on a later run after replacement completed', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $updater = new RunnerFakeUpdater;
        $updater->replaceResult = ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => true];

        $first = makeRunner($updater)->run();

        expect($first->status)->toBe(LocalUpdateResult::STATUS_COMPLETED)
            ->and($first->toVersion)->toBe('0.1.131');

        config()->set('app.version', '0.1.131');

        $updaterForSecondRun = new RunnerFakeUpdater;
        $second = makeRunner($updaterForSecondRun)->run();

        expect($second->status)->toBe(LocalUpdateResult::STATUS_SKIPPED_ALREADY)
            ->and($updaterForSecondRun->calls)->toBe([])
            ->and($second->stepResults)->toBe(['check' => 'skipped']);
    });

    it('returns failed step metadata when the download fails', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $updater = new RunnerFakeUpdater;
        $updater->downloadResult = [
            'successful' => false,
            'exit_code' => 6,
            'output' => 'curl: (6) Could not resolve host',
            'staged_path' => null,
            'version' => null,
        ];

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_FAILED)
            ->and($result->failedStep)->toBe('download')
            ->and($result->output)->toBe('curl: (6) Could not resolve host')
            ->and($updater->calls)->toBe(['download'])
            ->and($result->stepResults)->toBe([
                'check' => 'completed',
                'download' => 'failed',
            ]);
    });

    it('returns failed step metadata when the replace fails', function (): void {
        config()->set('app.version', '0.1.130');
        fakeRunnerLatest('0.1.131');
        fakeGateway(['gateway' => ['version' => '0.1.131']]);

        $updater = new RunnerFakeUpdater;
        $updater->replaceResult = [
            'successful' => false,
            'exit_code' => 1,
            'output' => 'ln: Permission denied',
            'skipped' => false,
        ];

        $result = makeRunner($updater)->run();

        expect($result->status)->toBe(LocalUpdateResult::STATUS_FAILED)
            ->and($result->failedStep)->toBe('replace')
            ->and($result->output)->toBe('ln: Permission denied')
            ->and($updater->calls)->toBe(['download', 'replace:/tmp/staged-orbit:0.1.131']);
    });
});
