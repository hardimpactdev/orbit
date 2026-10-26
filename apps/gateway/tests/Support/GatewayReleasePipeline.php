<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\BranchHeadReader;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Settings\SettingRepository;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTickClock;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\GatewayReleaseTickConfirmation;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;

/**
 * The whole Gateway release pipeline on a {@see GatewayReleaseFixture}: scripted agent answers, and the real
 * lock and records; fake runtime handoff, verifier, smoke, GitHub resolver, and alert notifier. A test sets
 * `$failVerify`, `$failSnapshot`, or `$green` to steer one release.
 */
final class GatewayReleasePipeline implements BranchHeadReader, GatewayReleaseDatabase, GatewayReleaseRuntime, GatewayReleaseSmoke, GatewayReleaseUnitStarter, GatewayReleaseVerifier, GatewayReleaseWebBuild, GreenCommitResolver, ReleaseAlertNotifier
{
    public readonly GatewayReleaseFixture $fixture;

    public readonly string $home;

    public readonly FakeAgentCommandRunner $recoveryAgents;

    /** @var array<string, array<string, mixed>> */
    private array $receipts = [];

    /** @var list<string> */
    public array $steps = [];

    public ?string $failVerify = null;

    /** Whether a Horizon master is registered, while the Gateway queue is set; null without the queue. */
    public ?\Closure $horizonRunning = null;

    /** The commit the resolver reports as green, or null for none. */
    public ?string $green = null;

    public ?GitHubApiException $githubFailure = null;

    /** The branch head GitHub reports, or null for the deployed commit. */
    public ?string $head = null;

    public int $headReads = 0;

    /** Whether the run unit of a queued record reports active. */
    public bool $unitActive = false;

    /** @var list<array{deployed: string, failed: list<string>, branch: string, check: string, repository: string}> */
    public array $resolutions = [];

    /** @var list<ReleaseAlert> */
    public array $alerts = [];

    /** @var array<string, mixed> what the schedule phase reports for the Gateway Node's own agent */
    public array $gatewayAgent = ['outcome' => 'unchanged', 'version' => '0.4.0'];

    public function __construct(private readonly bool $adopted = false)
    {
        $this->fixture = GatewayReleaseFixture::create(adopted: $adopted);
        $this->home = $this->fixture->base.'/home';
        mkdir($this->home, 0700, true);
        config(['orbit.home' => $this->home]);
        $this->recoveryAgents = new FakeAgentCommandRunner()
            ->answer('gateway.verify-release-recovery', ['verified' => true])
            ->answer('gateway.write-release-receipt', function (array $input): array {
                $this->receipts[$input['identity']['record'].':'.$input['identity']['sha']] = $input['attributes'];

                return ['changed' => true];
            })
            ->answer('gateway.read-release-receipt', fn (array $input): array => ['attributes' => $this->receipts[$input['record'].':'.$input['sha']] ?? []]);
        $this->answerRecoveryCopy();
    }

    /** Builds the first release and makes it current, as `gateway:release:adopt` would. */
    public function adopt(): string
    {
        if ($this->adopted) {
            return (string) $this->fixture->preparedSha($this->fixture->currentId());
        }
        $sha = $this->fixture->commit('Adopted release');
        $this->fixture->select($this->fixture->builder()->prepare($sha)->id);

        return $sha;
    }

    public function lock(): GatewayReleaseLock
    {
        return new GatewayReleaseLock($this->home.'/gateway-release.lock', $this->fixture->releaseCommands());
    }

    public function source(): GatewayReleaseSource
    {
        return new GatewayReleaseSource(
            commands: $this->fixture->releaseCommands(new FakeAgentCommandRunner()->answer('gateway.inspect-release-source', ['origin' => 'https://github.com/hardimpactdev/orbit.git'])),
            branch: 'main',
            checkName: 'Required checks',
            layout: $this->fixture->layout,
        );
    }

    public function recorder(): GatewayReleaseRecorder
    {
        $recovery = $this->recovery();

        return new GatewayReleaseRecorder($recovery, new GatewayReleaseRestore($recovery, $this->fixture->releaseCommands($this->recoveryAgents)), $this->home, new GatewayReleaseAlerts($this, $this->source()), $this->automation(), $this);
    }

    public function automation(): GatewayReleaseAutomation
    {
        return new GatewayReleaseAutomation(app(SettingRepository::class), $this->home.'/gateway-release.paused');
    }

    public function deployer(?GatewayReleaseDrain $drain = null): DeployGatewayReleaseAction
    {
        $recorder = $this->recorder();
        $builder = $this->fixture->builder($this);

        return new DeployGatewayReleaseAction($this->lock(), $builder, $this, $this->promoter($recorder, $this), $recorder, $this->guard(), new GatewayReleaseRetry, new GatewayReleaseSupersession($this->source(), $this), new ReleaseTestOwner, $drain);
    }

    public function rollback(?GatewayReleaseSmoke $smoke = null): RollbackGatewayReleaseAction
    {
        $recorder = $this->recorder();

        return new RollbackGatewayReleaseAction($this->lock(), $this->fixture->layout, $this->promoter($recorder, $smoke ?? $this), $recorder);
    }

    public function guard(): GatewayReleaseGuard
    {
        return new GatewayReleaseGuard($this->fixture->layout, $this->fixture->releaseCommands());
    }

    public function automatic(): RunAutomaticGatewayReleaseAction
    {
        return new RunAutomaticGatewayReleaseAction(
            $this->automation(),
            $this->lock(),
            $this->fixture->layout,
            $this->source(),
            $this,
            $this->deployer(),
            new GatewayReleaseAlerts($this, $this->source()),
            $this,
            $this->recorder(),
            $this->ticks(),
        );
    }

    /** The post-release tick confirmation, with the real tick clock and tasks extension state. */
    public function ticks(): GatewayReleaseTickConfirmation
    {
        return new GatewayReleaseTickConfirmation(app(TaskTickClock::class), app(TaskExtensionState::class), new GatewayReleaseAlerts($this, $this->source()), horizonRunning: $this->horizonRunning, horizonWaitSeconds: 1700);
    }

    /** Binds the pipeline into the container, for commands and API requests. */
    public function bind(): void
    {
        $this->bindRecovery();
        app()->instance(GatewayReleaseLayout::class, $this->fixture->layout);
        app()->instance(GatewayReleaseLock::class, $this->lock());
        app()->instance(GatewayReleaseRecorder::class, $this->recorder());
        app()->instance(GatewayReleaseAutomation::class, $this->automation());
        app()->instance(DeployGatewayReleaseAction::class, $this->deployer());
        app()->instance(RollbackGatewayReleaseAction::class, $this->rollback());
        app()->instance(RunAutomaticGatewayReleaseAction::class, $this->automatic());
    }

    /**
     * Binds only the leaf services: the layout, the lock, the builder, the switcher, and the fakes for
     * the runtime, verifier, web build, smoke, database, GitHub, units, and alerts. Every action,
     * the recorder, the promoter, and the supersession check come from the service provider, so a
     * test proves their production wiring.
     */
    public function bindLeaves(): void
    {
        $this->bindRecovery();
        app()->instance(GatewayReleaseExecutionOwner::class, new ReleaseTestOwner);
        app()->instance(GatewayReleaseLayout::class, $this->fixture->layout);
        app()->instance(GatewayReleaseLock::class, $this->lock());
        app()->instance(GatewayReleaseBuilder::class, $this->fixture->builder($this));
        app()->instance(GatewayReleaseSwitcher::class, new GatewayReleaseSwitcher($this->fixture->layout, $this->fixture->releaseCommands()));
        app()->instance(GatewayReleaseSource::class, $this->source());
        app()->instance(GatewayReleaseGuard::class, $this->guard());
        app()->instance(GatewayReleaseCommands::class, $this->fixture->releaseCommands());

        foreach ([
            GatewayReleaseDatabase::class, GatewayReleaseRuntime::class, GatewayReleaseVerifier::class, GatewayReleaseWebBuild::class,
            GatewayReleaseSmoke::class, GreenCommitResolver::class, BranchHeadReader::class, ReleaseAlertNotifier::class,
            GatewayReleaseUnitStarter::class,
        ] as $contract) {
            app()->instance($contract, $this);
        }
    }

    /**
     * A supersession check that finds no newer green commit, for tests that build a deploy action by
     * hand and do not exercise automatic releases.
     */
    public static function newestGreen(): GatewayReleaseSupersession
    {
        $resolver = new class implements GreenCommitResolver
        {
            public function resolve(GitHubRepository $repository, string $branch, string $checkName, string $deployedSha, array $failedShas = []): ?GreenCommit
            {
                return null;
            }
        };
        $origin = new GatewayReleaseCommands(new FakeAgentCommandRunner()->answer('gateway.inspect-release-source', ['origin' => 'https://github.com/hardimpactdev/orbit.git']));

        return new GatewayReleaseSupersession(new GatewayReleaseSource($origin, 'main', 'Required checks', new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway')), $resolver);
    }

    /** Produce the sample file that the real PHP restore transaction compares; Rust tests own copy checks. */
    public function answerRecoveryCopy(): void
    {
        $this->recoveryAgents->answer('gateway.protect-release-recovery', function (array $input): array {
            $directory = $this->home.'/recovery';
            if (! is_dir($directory)) {
                mkdir($directory, 0700);
            }
            $path = $directory.'/stage-'.$input['identity']['record'].'-'.$input['identity']['sha'].'.sqlite';
            copy($input['source'], $path);
            chmod($path, 0600);

            return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
        });
    }

    private function recovery(): GatewayReleaseRecovery
    {
        return new GatewayReleaseRecovery($this->fixture->releaseCommands($this->recoveryAgents));
    }

    private function bindRecovery(): void
    {
        $recovery = $this->recovery();
        app()->instance(GatewayReleaseRecovery::class, $recovery);
        app()->instance(GatewayReleaseRestore::class, new GatewayReleaseRestore($recovery, $this->fixture->releaseCommands($this->recoveryAgents)));
    }

    public function cleanup(): void
    {
        $this->fixture->cleanup();
    }

    public function start(int $record, \Closure $claimed): void {}

    public function isActive(int $record): bool
    {
        return $this->unitActive;
    }

    public function head(GitHubRepository $repository, string $branch): string
    {
        $this->headReads++;

        if ($this->githubFailure instanceof GitHubApiException) {
            throw $this->githubFailure;
        }

        return $this->head ?? (string) $this->fixture->layout->preparedCommit((string) $this->fixture->layout->currentReleaseId());
    }

    public function resolve(GitHubRepository $repository, string $branch, string $checkName, string $deployedSha, array $failedShas = []): ?GreenCommit
    {
        $this->resolutions[] = [
            'deployed' => $deployedSha,
            'failed' => $failedShas,
            'branch' => $branch,
            'check' => $checkName,
            'repository' => $repository->owner.'/'.$repository->name,
        ];

        if ($this->githubFailure instanceof GitHubApiException) {
            throw $this->githubFailure;
        }

        return $this->green === null ? null : new GreenCommit($this->green, new GitHubCheckRun($checkName, 'success', null, status: 'completed', headSha: $this->green));
    }

    public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
    {
        $this->alerts[] = $alert;

        return new ReleaseAlertReceipt('0198e15c-bf97-7c23-8f1f-61b8fe67a844', count($this->alerts), ReleaseAlertStep::done('release|fingerprint'), ReleaseAlertStep::skipped('not_configured'));
    }

    public bool $failSnapshot = false;

    public int $snapshots = 0;

    public bool $failMigrate = false;

    public function snapshot(string $id): string
    {
        $this->snapshots++;
        if ($this->failSnapshot) {
            throw new GatewayReleaseException('snapshot', 'gateway.release_disk_low', 'Not enough room for the snapshot.', 500);
        }

        $directory = $this->home.'/backups';
        if (! is_dir($directory)) {
            mkdir($directory, 0700);
        }
        $path = $directory.'/pre-'.$id.'-'.$this->snapshots.'.sqlite';
        $database = new \PDO('sqlite:'.$path);
        $database->exec('CREATE TABLE snapshot_fixture (id INTEGER PRIMARY KEY)');
        chmod($path, 0600);

        return $path;
    }

    public function migrate(string $releasePath): void
    {
        $this->steps[] = 'migrate';
        if ($this->failMigrate) {
            throw new GatewayReleaseException('migrate', 'gateway.release_migrate_failed', 'Interrupted schema change.');
        }
    }

    public function snapshotBytes(): int
    {
        return 0;
    }

    public function handoff(string $id): array
    {
        $this->steps[] = 'handoff:'.$id;

        return [
            'caddy' => 'unchanged',
            'fpm' => 'unchanged',
            'scheduler' => 'restarted',
            'cleanup' => 'skipped',
            'agent_view' => 'restarted',
            'cleanup_paused' => false,
        ];
    }

    public function serving(): array
    {
        return ['status' => 'ok', 'version' => 'dev'];
    }

    public function schedule(string $id): array
    {
        $this->steps[] = 'schedule:'.$id;

        return ['scheduler' => 'restarted', 'cleanup' => 'skipped', 'cleanup_error_code' => null, 'cleanup_paused' => false, 'gateway_agent' => $this->gatewayAgent];
    }

    /** @var (\Closure(): void)|null runs when verify starts */
    public ?\Closure $onVerify = null;

    public function verify(string $sha): array
    {
        $this->steps[] = 'verify';

        if ($this->onVerify !== null) {
            ($this->onVerify)();
        }

        if ($this->failVerify === $sha) {
            throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', "The Gateway version is not [{$sha}].");
        }

        return ['status' => 'ok', 'version' => $sha];
    }

    public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
    {
        $this->steps[] = 'smoke';

        return ['outcome' => 'passed'];
    }

    public function install(string $id, string $sha): bool
    {
        return false;
    }

    public function publish(string $id): void
    {
        $this->steps[] = 'web:publish';
    }

    public function restore(string $id): void
    {
        $this->steps[] = 'web:restore';
    }

    public function remove(string $id): void {}

    public function prune(array $retained): void {}

    private function promoter(GatewayReleaseRecorder $recorder, GatewayReleaseSmoke $smoke): GatewayReleasePromoter
    {
        $builder = $this->fixture->builder($this);

        return new GatewayReleasePromoter(
            $this->fixture->layout,
            new GatewayReleaseSwitcher($this->fixture->layout, $this->fixture->releaseCommands()),
            $this,
            $this,
            $this,
            $smoke,
            $recorder,
            $builder,
            Config::integer('orbit.gateway_releases.keep'),
            ticks: $this->ticks(),
        );
    }
}
