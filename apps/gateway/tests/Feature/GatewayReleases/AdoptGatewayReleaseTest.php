<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdoptGatewayReleaseAction;
use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdoptionFiles;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Models\Activity;
use App\Models\GatewayRelease;
use App\Models\Node;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FakeFleetConvergeUnits;
use Tests\Support\GatewayAdoptionFixture;
use Tests\Support\GatewayReleaseFixture;
use Tests\Support\GatewayReleasePipeline;
use Tests\Support\ReleaseTestOwner;
use Tests\Support\WebArtifactFixture;

beforeEach(function (): void {
    $this->fixture = GatewayReleaseFixture::create();
    $this->adoptionFixture = new GatewayAdoptionFixture($this->fixture);
    $this->sha = $this->adoptionFixture->sha;
    $this->id = substr($this->sha, 0, 12);
    $this->source = $this->fixture->base.'/adopt-source';
    $this->steps = new AdoptionSteps;
    config(['orbit.home' => $this->fixture->base.'/home']);
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.1']);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

describe('gateway:release:adopt', function (): void {
    it('records the original refusal and the failed environment recovery when the agent cannot restore it', function (): void {
        $agents = new FakeAgentCommandRunner;
        $agents->answer('gateway.check-release-adoption', ['sha' => $this->sha])
            ->refuse('gateway.adopt-release-repository', 'gateway.release_adopt_repository_failed')
            ->refuse('gateway.restore-adoption-environment', 'gateway.release_adopt_env_failed');

        $exception = release_failure(fn () => adoption($this->fixture, $this->steps, agents: $agents)->execute());

        expect($exception->errorCode)->toBe('gateway.release_adopt_repository_failed');
        expect(Activity::query()->value('error_code'))->toBe('gateway.release_adopt_repository_failed');
        expect(array_column($agents->calls, 'command'))->toBe(['gateway.check-release-adoption', 'gateway.adopt-release-repository', 'gateway.restore-adoption-environment']);
        expect(GatewayRelease::query()->sole()->phases['environment'])->toBe(['outcome' => 'failed', 'error_code' => 'gateway.release_adopt_env_failed']);
        expect($this->steps->steps)->toBe([]);
    });

    it('adopts the checkout commit before deploying and verifying the target', function (): void {
        $result = adoption($this->fixture, $this->steps)->execute();
        $layout = $this->fixture->layout;
        $kept = $layout->basePath().'/orbit.pre-adopt-20261007T120000Z';

        expect($result['release'])->toBe($this->id)
            ->and($result['switch']['method'])->toBe('exchange')
            ->and($result['switch']['gap_us'])->toBe(0)
            ->and($result['pre_adopt_path'])->toBe($kept)
            ->and($result['phase1']['release'])->toBe($this->id)
            ->and($result['deploy']['outcome'])->toBe('verified')
            ->and($layout->currentReleaseId())->toBe($this->id)
            ->and($result['shared'])->toMatchArray(['storage' => 'moved', 'storage_method' => 'exchange', 'storage_gap_us' => 0])
            // Phase 1 prepares the release with its web build; phase 2 reuses that release and installs the web build again only when it is missing.
            ->and($this->steps->installed)->toBe([$this->id, $this->id])
            // Phase 1 changes the layout with identical code and checks that it serves; phase 2 is a deploy.
            ->and($this->steps->steps)->toBe([
                'handoff:'.$this->id, 'serving', 'schedule:'.$this->id,
                'snapshot:'.$this->id, 'migrate:'.$this->id,
                'handoff:'.$this->id, 'verify:'.$this->sha, 'schedule:'.$this->id, 'web:publish:'.$this->id, 'smoke:'.$this->id,
            ])
            ->and(GatewayRelease::query()->pluck('trigger')->all())->toBe(['adopt', 'adopt'])
            ->and(GatewayRelease::query()->pluck('outcome')->all())->toBe(['verified', 'verified'])
            ->and(Activity::query()->value('command'))->toBe('gateway:release:adopt');
        expect($this->adoptionFixture->agents->commands())->toBe([
            'gateway.check-release-adoption', 'gateway.adopt-release-repository',
            'gateway.adopt-release-environment', 'gateway.adopt-release-storage', 'gateway.switch-release-adoption',
        ]);
        expect($this->adoptionFixture->agents->calls[1]['input'])->toBe(['checkout' => $layout->applicationPath, 'sha' => $this->sha])
            ->and($this->adoptionFixture->agents->calls[4]['input'])->toBe(['checkout' => $layout->applicationPath, 'release' => $this->id, 'stamp' => '20261007T120000Z']);
    });

    it('starts the fleet rollout only after phase 2 verifies the exact commit, never after phase 1', function (): void {
        $target = $this->fixture->commit('Ships the fleet rollout');
        $this->fixture->releaseFile(substr($target, 0, 12), 'apps/gateway/'.GatewayReleasePromoter::FleetCommand, "<?php\n");
        $fleet = new FakeFleetConvergeUnits;
        $this->steps->failVerify = true;

        release_failure(fn () => adoption($this->fixture, $this->steps, fleet: $fleet)->execute($target, $this->source));

        // Phase 1 recorded `verified` after a serving check, and phase 2 failed: the fleet stays where it is.
        expect(GatewayRelease::query()->orderBy('id')->pluck('outcome')->first())->toBe('verified')
            ->and($fleet->started)->toBe(0);

        $this->steps->failVerify = false;
        $fix = $this->fixture->commit('Forward fix');
        $this->fixture->releaseFile(substr($fix, 0, 12), 'apps/gateway/'.GatewayReleasePromoter::FleetCommand, "<?php\n");
        adoption($this->fixture, $this->steps, fleet: $fleet)->execute($fix, $this->source);

        expect($fleet->started)->toBe(1);
    });

    it('does nothing the second time', function (): void {
        adoption($this->fixture, $this->steps)->execute();

        $again = adoption($this->fixture, $this->steps)->execute();

        expect($again)->toMatchArray(['adopted' => true, 'already' => true, 'release' => $this->id])
            ->and(GatewayRelease::query()->count())->toBe(2);
    });

    it('adopts into a newer commit in two phases: the checkout\'s own commit first, then a deploy with migrations', function (): void {
        $target = $this->fixture->commit('Newer release with a migration');
        $targetId = substr($target, 0, 12);

        $result = adoption($this->fixture, $this->steps)->execute(substr($target, 0, 10), $this->source);

        expect(collect($this->adoptionFixture->agents->calls)->firstWhere('command', 'gateway.resolve-adoption-target')['input'])
            ->toBe(['checkout' => $this->fixture->layout->applicationPath, 'target' => substr($target, 0, 10), 'source' => $this->source])
            ->and($result)->toMatchArray(['release' => $targetId, 'sha' => $target, 'from' => $this->sha])
            ->and($result['phase1']['release'])->toBe($this->id)
            ->and($result['deploy']['migrations_ran'])->toBeTrue()
            ->and($result['deploy']['previous'])->toBe($this->id)
            ->and($this->fixture->layout->currentReleaseId())->toBe($targetId)
            // The first release serves the checkout's commit, which may predate the CI web build.
            ->and($this->steps->installed)->toBe([$targetId])
            ->and($this->steps->steps)->toBe([
                'handoff:'.$this->id, 'serving', 'schedule:'.$this->id,
                'snapshot:'.$targetId, 'migrate:'.$targetId,
                'handoff:'.$targetId, 'verify:'.$target, 'schedule:'.$targetId, 'web:publish:'.$targetId, 'smoke:'.$targetId,
            ])
            ->and($this->steps->servingDuringMigrate)->toBe('release '.$this->id)
            ->and(GatewayRelease::query()->orderBy('id')->pluck('release_id')->all())->toBe([$this->id, $targetId]);
    });

    it('adopts into a commit with its CI web build installed and published, and smokes the release', function (): void {
        $arguments = $this->fixture->base.'/smoke-args';
        $target = $this->fixture->commit('Newer release with smoke');
        $targetId = substr($target, 0, 12);
        $this->fixture->releaseFile($targetId, 'bin/gateway-smoke', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($arguments)."\necho '{\"schema\":1,\"passed\":true,\"checks\":{}}'\n", 0755);
        $web = new WebArtifactFixture($this->fixture->layout, $this->fixture->base.'/web');
        $web->github(sha: $target);
        $smoke = new ScriptGatewayReleaseSmoke(
            layout: $this->fixture->layout,
            processes: new NativeProcessRunner,
            origin: 'https://gateway.orbit',
            webRoot: $web->web,
        );

        $result = adoption($this->fixture, $this->steps, web: $web->build(), smoke: $smoke)->execute($target, $this->source);
        $passed = file($arguments, FILE_IGNORE_NEW_LINES);
        $record = GatewayRelease::query()->where('release_id', $targetId)->sole();

        expect($result['release'])->toBe($targetId)
            ->and($result['deploy']['phases']['web'])->toBe(['outcome' => 'published'])
            ->and($web->agents->commands())->toContain('gateway.install-web-build', 'gateway.publish-web-build')
            ->and(collect($web->agents->calls)->firstWhere('command', 'gateway.publish-web-build')['input'])->toBe(['web_root' => $web->web, 'release' => $targetId])
            ->and($passed[array_search('--sha', $passed, true) + 1])->toBe($target)
            ->and($passed)->toContain('--since')
            ->and($record->outcome)->toBe('verified')
            ->and($record->phases['smoke'])->toBe(['outcome' => 'passed', 'report' => ['schema' => 1, 'passed' => true, 'checks' => []]]);
    });

    it('records phase 1 after phase 2 when the checkout\'s database has no release records yet', function (): void {
        $target = $this->fixture->commit('Adds the release records');
        Schema::rename('gateway_releases', 'gateway_releases_later');
        $this->steps->onMigrate = static fn () => Schema::rename('gateway_releases_later', 'gateway_releases');

        adoption($this->fixture, $this->steps)->execute($target, $this->source);

        expect(GatewayRelease::query()->pluck('release_id')->sort()->values()->all())->toEqualCanonicalizing([$this->id, substr($target, 0, 12)])
            ->and(GatewayRelease::query()->where('release_id', $this->id)->value('outcome'))->toBe('verified');
    });

    it('stays adopted on the checkout\'s commit and pauses when the target\'s migration fails', function (): void {
        $target = $this->fixture->commit('Broken migration');
        $this->steps->failMigrate = true;

        $exception = release_failure(fn () => adoption($this->fixture, $this->steps)->execute($target, $this->source));

        expect($exception->errorCode)->toBe('gateway.release_migrate_failed')
            ->and($this->fixture->layout->currentReleaseId())->toBe($this->id)
            ->and(GatewayRelease::query()->orderBy('id')->pluck('outcome')->all())->toBe(['verified', 'paused']);
    });

    it('records a tracked-change refusal without starting adoption', function (): void {
        $this->adoptionFixture->agents->refuse('gateway.check-release-adoption', 'gateway.release_adopt_local_changes', ['count' => 1, 'path' => 'apps/gateway/public/index.php']);

        $exception = release_failure(fn () => adoption($this->fixture, $this->steps)->execute());

        expect($exception->errorCode)->toBe('gateway.release_adopt_local_changes')
            ->and($exception->getMessage())->toContain('apps/gateway/public/index.php')
            ->and($this->adoptionFixture->agents->commands())->toBe(['gateway.check-release-adoption', 'gateway.restore-adoption-environment'])
            ->and(Activity::query()->value('error_code'))->toBe('gateway.release_adopt_local_changes');
    });

    it('requests checkout and environment restoration after a serving failure and retries', function (): void {
        $this->steps->failServing = true;

        $exception = release_failure(fn () => adoption($this->fixture, $this->steps)->execute());

        expect($exception->errorCode)->toBe('gateway.release_verify_failed')
            ->and(array_slice($this->adoptionFixture->agents->commands(), -2))->toBe(['gateway.restore-release-adoption', 'gateway.restore-adoption-environment'])
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('switched_back')
            // The scheduler had not moved yet, so only the serving side is handed back.
            ->and($this->steps->steps)->toBe(['handoff:'.$this->id, 'serving', 'handoff:'.$this->id]);

        $this->steps->failServing = false;
        $result = adoption($this->fixture, $this->steps)->execute();

        expect($result['shared'])->toMatchArray(['repository' => 'existing', 'env' => 'existing', 'env_link' => 'linked', 'storage' => 'existing'])
            ->and($this->fixture->layout->currentReleaseId())->toBe($this->id);
    });

    it('finishes an adoption that swapped but never verified when it runs again', function (): void {
        adoption($this->fixture, $this->steps)->execute();
        // The first run died between the swap and the end: no release verified the current release.
        GatewayRelease::query()->delete();
        $this->steps->steps = [];

        $result = adoption($this->fixture, $this->steps)->execute();

        expect($result)->toMatchArray(['already' => true, 'resumed' => true, 'release' => $this->id])
            ->and(array_slice($this->steps->steps, 0, 3))->toBe(['handoff:'.$this->id, 'serving', 'schedule:'.$this->id])
            // The serving check alone does not verify the version; phase 2 does.
            ->and(GatewayRelease::query()->orderBy('id')->pluck('outcome')->all())->toBe(['resumed', 'verified']);
    });

    it('records a resumed adoption once phase 2 has created the record table', function (): void {
        adoption($this->fixture, $this->steps)->execute();
        GatewayRelease::query()->delete();
        Schema::rename('gateway_releases', 'gateway_releases_later');
        $target = $this->fixture->commit('Adds the release records');
        $this->steps->onMigrate = static fn () => Schema::rename('gateway_releases_later', 'gateway_releases');

        $result = adoption($this->fixture, $this->steps)->execute($target, $this->source);

        expect($result['resumed'])->toBeTrue()
            ->and(GatewayRelease::query()->where('release_id', $this->id)->value('outcome'))->toBe('resumed')
            ->and(GatewayRelease::query()->where('release_id', substr($target, 0, 12))->value('outcome'))->toBe('verified');
    });

    it('keeps a phase-2 pause when adopt runs again, and does not resume a release that is not phase 1\'s', function (): void {
        $target = $this->fixture->commit('Migrates, then fails verify');
        $this->steps->failVerify = true;
        $marker = $this->fixture->base.'/home/gateway-release.paused';

        release_failure(fn () => adoption($this->fixture, $this->steps)->execute($target, $this->source));
        $pausedFirst = is_file($marker);
        $this->steps->steps = [];
        $again = adoption($this->fixture, $this->steps)->execute($target, $this->source);

        expect($pausedFirst)->toBeTrue()
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($target, 0, 12))
            ->and($again)->toMatchArray(['already' => true, 'release' => substr($target, 0, 12)])
            ->and($again)->not->toHaveKey('resumed')
            ->and($this->steps->steps)->toBe([])
            ->and(is_file($marker))->toBeTrue()
            ->and(GatewayRelease::query()->orderBy('id')->pluck('outcome')->all())->toBe(['verified', 'paused']);
    });

    it('keeps the pause marker for a resumed adoption and clears it only for a verified release', function (): void {
        $marker = $this->fixture->base.'/home/gateway-release.paused';
        @mkdir(dirname($marker), 0700, true);
        $recorder = new GatewayReleaseRecorder(app(GatewayReleaseRecovery::class), app(GatewayReleaseRestore::class), $this->fixture->base.'/home');
        $release = static fn (string $outcome): DeployedGatewayRelease => new DeployedGatewayRelease(
            id: '0123456789ab', sha: str_repeat('a', 40), outcome: $outcome, trigger: 'adopt', migrationsRan: false,
            previousId: null, snapshotPath: null, cleanupPaused: false, retryable: false, durationMs: 1, phases: [],
        );
        file_put_contents($marker, '{"release":"0123456789ab"}');

        $recorder->write($release('resumed'));
        $keptAfterResume = is_file($marker);
        $recorder->write($release('verified'));

        expect($keptAfterResume)->toBeTrue()
            ->and(is_file($marker))->toBeFalse();
    });

    it('refuses when the shared env file differs from the checkout', function (): void {
        $this->adoptionFixture->agents->refuse('gateway.adopt-release-environment', 'gateway.release_adopt_env_conflict');

        expect(release_failure(fn () => adoption($this->fixture, $this->steps)->execute())->errorCode)->toBe('gateway.release_adopt_env_conflict')
            ->and($this->adoptionFixture->agents->commands())->not->toContain('gateway.switch-release-adoption');
    });

    it('refuses a commit that is not a hex SHA before it changes anything', function (): void {
        $exception = release_failure(fn () => adoption($this->fixture, $this->steps)->execute('main'));

        expect($exception->errorCode)->toBe('gateway.release_commit_invalid')
            ->and($this->adoptionFixture->agents->commands())->toBeEmpty();
    });

    it('prints the adoption as one JSON object', function (): void {
        $this->app->instance(AdoptGatewayReleaseAction::class, adoption($this->fixture, $this->steps));

        $this->artisan('gateway:release:adopt')
            ->expectsOutputToContain('"release":"'.$this->id.'"')
            ->assertExitCode(0);
    });
});

function adoption(
    GatewayReleaseFixture $fixture,
    AdoptionSteps $steps,
    ?GatewayReleaseWebBuild $web = null,
    ?GatewayReleaseSmoke $smoke = null,
    ?FleetConvergeUnits $fleet = null,
    ?AgentCommandRunner $agents = null,
): AdoptGatewayReleaseAction {
    $web ??= new AdoptionWebBuild($steps);
    $database = new AdoptionDatabase($steps, $fixture);
    $runtime = new AdoptionRuntime($steps);
    $verifier = new AdoptionVerifier($steps);
    $smoke ??= new AdoptionSmoke($steps);
    $builder = $fixture->builder($web);
    $recorder = new GatewayReleaseRecorder(app(GatewayReleaseRecovery::class), app(GatewayReleaseRestore::class), $fixture->base.'/home');
    $guard = new GatewayReleaseGuard($fixture->layout, $fixture->releaseCommands());
    $lock = new GatewayReleaseLock($fixture->base.'/home/gateway-release.lock');
    $deploy = new DeployGatewayReleaseAction(
        $lock,
        $builder,
        $database,
        new GatewayReleasePromoter($fixture->layout, new GatewayReleaseSwitcher($fixture->layout, $fixture->releaseCommands()), $runtime, $verifier, $web, $smoke, $recorder, $builder, Config::integer('orbit.gateway_releases.keep'), fleet: $fleet),
        $recorder,
        $guard,
        new GatewayReleaseRetry,
        GatewayReleasePipeline::newestGreen(),
        new ReleaseTestOwner,
    );

    return new AdoptGatewayReleaseAction(
        $lock,
        new GatewayReleaseAdopter(
            layout: $fixture->layout,
            builder: $builder,
            files: new GatewayReleaseAdoptionFiles($agents ?? test()->adoptionFixture->agents),
            runtime: $runtime,
            verifier: $verifier,
            recorder: $recorder,
            deploy: $deploy,
            clock: static fn (): string => '20261007T120000Z',
        ),
    );
}

final class AdoptionSteps
{
    /** @var list<string> */
    public array $steps = [];

    /** @var list<string> */
    public array $installed = [];

    public bool $failServing = false;

    public bool $failVerify = false;

    public bool $failMigrate = false;

    public bool $failSmoke = false;

    public ?string $servingDuringMigrate = null;

    public ?Closure $onMigrate = null;
}

final readonly class AdoptionRuntime implements GatewayReleaseRuntime
{
    public function __construct(private AdoptionSteps $steps) {}

    public function handoff(string $id): array
    {
        $this->steps->steps[] = 'handoff:'.$id;

        return ['caddy' => 'reloaded', 'fpm' => 'unchanged', 'agent_view' => 'restarted'];
    }

    public function schedule(string $id): array
    {
        $this->steps->steps[] = 'schedule:'.$id;

        return ['scheduler' => 'restarted', 'cleanup' => 'skipped', 'cleanup_paused' => false];
    }
}

final readonly class AdoptionVerifier implements GatewayReleaseVerifier
{
    public function __construct(private AdoptionSteps $steps) {}

    public function verify(string $sha): array
    {
        $this->steps->steps[] = 'verify:'.$sha;

        if ($this->steps->failVerify) {
            throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', 'Version mismatch.');
        }

        return ['status' => 'ok', 'version' => $sha];
    }

    public function serving(): array
    {
        $this->steps->steps[] = 'serving';

        if ($this->steps->failServing) {
            throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', 'Gateway /up did not succeed.');
        }

        return ['status' => 'ok', 'version' => '0.1.0'];
    }
}

final readonly class AdoptionSmoke implements GatewayReleaseSmoke
{
    public function __construct(private AdoptionSteps $steps) {}

    public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
    {
        $this->steps->steps[] = 'smoke:'.$id;

        if ($this->steps->failSmoke) {
            throw new GatewayReleaseException('smoke', 'gateway.release_smoke_failed', 'Smoke failed.', phase: ['report' => ['passed' => false]]);
        }

        return ['outcome' => 'passed', 'report' => ['passed' => true]];
    }
}

/** The web build that prepare installs and a release publishes or restores, in the order it happens. */
final readonly class AdoptionWebBuild implements GatewayReleaseWebBuild
{
    public function __construct(private AdoptionSteps $steps) {}

    public function install(string $id, string $sha): bool
    {
        $this->steps->installed[] = $id;

        return true;
    }

    public function publish(string $id): void
    {
        $this->steps->steps[] = 'web:publish:'.$id;
    }

    public function restore(string $id): void
    {
        $this->steps->steps[] = 'web:restore:'.$id;
    }

    public function remove(string $id): void {}

    public function prune(array $retained): void {}
}

final readonly class AdoptionDatabase implements GatewayReleaseDatabase
{
    public function __construct(private AdoptionSteps $steps, private GatewayReleaseFixture $fixture) {}

    public function snapshot(string $id): string
    {
        $this->steps->steps[] = 'snapshot:'.$id;

        return '/var/tmp/orbit-pre-'.$id.'.sqlite';
    }

    public function migrate(string $releasePath): void
    {
        $this->steps->steps[] = 'migrate:'.basename($releasePath);
        $this->steps->servingDuringMigrate = 'release '.$this->fixture->layout->currentReleaseId();

        if ($this->steps->failMigrate) {
            throw new GatewayReleaseException('migrate', 'gateway.release_migrate_failed', 'The release migrations failed.', 500);
        }

        if ($this->steps->onMigrate instanceof Closure) {
            ($this->steps->onMigrate)();
        }

    }

    public function snapshotBytes(): int
    {
        return 0;
    }
}
