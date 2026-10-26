<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Models\GatewayRelease;
use Closure;
use Throwable;

/**
 * Converts the in-place checkout at `/home/orbit/orbit` into the release layout, once, while it keeps serving, in two
 * phases. The first changes the layout but not the code; the second changes the code through a normal deploy.
 *
 * Phase 1, the checkout's own commit:
 *
 * 1. refuses when the checkout has tracked changes;
 * 2. creates `shared/orbit.git` as a bare clone of the checkout's own repository, with its origin, and fetches the
 *    target commit from the checkout this command runs from when the clone lacks it;
 * 3. writes `shared/gateway.env` from the checkout's `.env`, without `APP_VERSION`, keeps the original as
 *    `.env.pre-adopt`, and replaces `.env` with a link to the shared file in one rename. `.env.bak*` files are copied
 *    to `shared/env-backups/`;
 * 4. moves `apps/gateway/storage` to `shared/gateway-storage` and links it back, so the checkout keeps working;
 * 5. prepares `releases/<id>` for the checkout's own commit, so the code that serves does not change;
 * 6. swaps the checkout directory with a link to that release in one step, keeps the directory as
 *    `orbit.pre-adopt-<time>`, hands the runtime over with this process's code, and checks that the Gateway serves.
 *
 * A failure in phase 1 leaves the checkout serving with its original `.env` back in place. The storage link stays:
 * it reaches the same files.
 *
 * Phase 2, only when the target is another commit: {@see DeployGatewayReleaseAction::deploy()} releases it like any
 * deploy, with its snapshot, migrations, handoff, verify, web build, and smoke, and switches back or pauses on failure.
 *
 * Every step can run again. A rerun finishes an adoption that swapped but never verified, then deploys the target.
 */
final readonly class GatewayReleaseAdopter
{
    /** @var Closure(): string */
    private Closure $clock;

    /**
     * @param  GatewayReleaseRuntime  $runtime  runs the handoff in this process, for a release that may predate the command
     * @param  (Closure(): string)|null  $clock  UTC time for the kept directory's name
     */
    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseBuilder $builder,
        private GatewayReleaseAdoptionFiles $files,
        private GatewayReleaseRuntime $runtime,
        private GatewayReleaseVerifier $verifier,
        private GatewayReleaseRecorder $recorder,
        private DeployGatewayReleaseAction $deploy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Ymd\\THis\\Z');
    }

    /**
     * @param  string|null  $commit  The commit to adopt into, as a hex SHA. Null adopts the checkout's own commit.
     * @param  string|null  $source  A checkout of that commit, such as the one this command runs from, to fetch it from.
     * @return array<string, mixed>
     */
    public function adopt(?string $commit = null, ?string $source = null): array
    {
        $target = $commit === null ? null : GatewayReleaseCommit::parse($commit);
        $inventory = $this->layout->observe();
        $layout = $inventory->current !== null
            ? $this->resume($inventory->current)
            : $this->convert($target, $source);
        $inventory = $this->layout->observe();
        $current = (string) $inventory->current;
        $prepared = $inventory->commits[$current] ?? null;
        $sha = $target === null ? (string) $prepared : $this->files->target($this->layout, $target, $source);

        // A finished adoption already on the target has nothing left to do.
        if ($layout['already'] === true && ! isset($layout['resumed']) && $sha === $prepared) {
            return $layout;
        }

        // Phase 2 runs the target through the deploy path. For the checkout's own commit it installs the web build,
        // verifies the exact version, and runs smoke, which phase 1 does not.
        try {
            $deployed = $this->deploy->deploy($sha, false, 'adopt');
        } finally {
            // A checkout that predates the release records has no table for phase 1 until phase 2 migrates.
            $unrecorded = $layout['unrecorded'] ?? null;

            if ($unrecorded instanceof DeployedGatewayRelease) {
                $this->record($unrecorded);
            }
        }

        unset($layout['unrecorded']);

        return [...$layout, 'adopted' => true, 'release' => $deployed->id, 'sha' => $deployed->sha, 'deploy' => $deployed->toArray()];
    }

    /**
     * Phase 1: the layout around the checkout's own commit.
     *
     * @return array<string, mixed>
     */
    private function convert(?string $target, ?string $source): array
    {
        $startedAt = hrtime(true);
        $current = $this->layout->currentPath();
        $head = null;
        $phases = [];
        $step = 'adopt';

        try {
            $head = $this->files->checkout($this->layout);
            $id = GatewayReleaseCommit::id($head);
            $shared = ['repository' => $this->files->repository($this->layout, $head)];

            if ($target !== null) {
                $this->files->target($this->layout, $target, $source);
            }

            $shared = [...$shared, ...$this->files->environment($this->layout), ...$this->files->storage($this->layout)];
            $phases['shared'] = $shared;
            $step = 'prepare';
            // Without --commit the checkout's commit is the target too, so its release gets its web build.
            $prepared = $this->builder->prepare($head, withWeb: $target === null || str_starts_with($head, $target));
            $phases['prepare'] = ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs];
            $step = 'configuration';
            $this->builder->refreshConfiguration($id);
            $phases['configuration'] = ['outcome' => 'cached'];
            $step = 'switch';
            $stamp = ($this->clock)();
            $kept = $this->layout->basePath().'/'.basename($current).'.pre-adopt-'.$stamp;
            $switch = $this->files->switch($this->layout, $id, $stamp);
            $phases['switch'] = $switch;
        } catch (Throwable $thrown) {
            $exception = GatewayReleaseException::fromThrowable($thrown, $step, $head);
            $phases[$exception->step] = [...$exception->phase, 'outcome' => 'failed', 'error_code' => $exception->errorCode];
            try {
                $phases['environment'] = $this->files->restoreEnvironment($this->layout);
            } catch (GatewayReleaseException $restore) {
                $phases['environment'] = ['outcome' => 'failed', 'error_code' => $restore->errorCode];
            }

            if ($head === null || ! isset($id)) {
                $this->recorder->refused('adopt', $exception, $this->elapsed($startedAt));
            } else {
                $this->record($this->outcome($id, $head, 'failed', $phases, $startedAt, $exception));
            }

            throw $exception;
        }

        $step = 'handoff';
        $scheduled = false;

        try {
            $phases['handoff'] = $this->runtime->handoff($id);
            $step = 'verify';
            $serving = $this->verifier->serving();
            $phases['verify'] = ['outcome' => 'passed', 'status' => $serving['status'], 'version' => $serving['version']];
            $step = 'scheduler';
            $scheduled = true;
            $phases['scheduler'] = $this->runtime->schedule($id);
        } catch (Throwable $thrown) {
            throw $this->switchBack(GatewayReleaseException::fromThrowable($thrown, $step, $head), $current, $kept, $id, $head, $phases, $startedAt, $scheduled);
        }

        $verified = $this->outcome($id, $head, 'verified', $phases, $startedAt);

        return [
            'unrecorded' => $this->record($verified) ? null : $verified,
            'adopted' => true,
            'already' => false,
            'from' => $head,
            'pre_adopt_path' => $kept,
            'shared' => $shared,
            'switch' => $switch,
            'phase1' => ['release' => $id, 'handoff' => $phases['handoff'], 'verify' => $phases['verify'], 'scheduler' => $phases['scheduler'], 'duration_ms' => $this->elapsed($startedAt)],
        ];
    }

    /**
     * An adopted Gateway: done when a release verified the current release; otherwise an earlier run swapped and
     * stopped before it verified, so the handoff and the serving check run again.
     *
     * @return array<string, mixed>
     */
    private function resume(string $id): array
    {
        $leftover = $this->finishLeftover();

        // Only phase 1's own release can be an interrupted adoption. Any other current release came from a deploy,
        // which records its own outcome, such as a pause, and must not be resumed here.
        if ($this->verified($id) || ! $this->files->phaseOne($this->layout, $id)) {
            return ['adopted' => true, 'already' => true, 'release' => $id, 'pre_adopt_path' => $leftover];
        }

        $startedAt = hrtime(true);
        $sha = (string) $this->layout->preparedCommit($id);
        $phases = ['resume' => ['outcome' => 'started']];
        $step = 'handoff';

        try {
            $phases['handoff'] = $this->runtime->handoff($id);
            $step = 'verify';
            $serving = $this->verifier->serving();
            $phases['verify'] = ['outcome' => 'passed', 'status' => $serving['status'], 'version' => $serving['version']];
            $step = 'scheduler';
            $phases['scheduler'] = $this->runtime->schedule($id);
        } catch (Throwable $thrown) {
            $exception = GatewayReleaseException::fromThrowable($thrown, $step, $sha);
            $phases[$exception->step] = [...$exception->phase, 'outcome' => 'failed', 'error_code' => $exception->errorCode];
            $this->record($this->outcome($id, $sha, 'failed', $phases, $startedAt, $exception));

            throw $exception;
        }

        // Only the serving check ran; phase 2's deploy verifies the exact version.
        $resumed = $this->outcome($id, $sha, 'resumed', $phases, $startedAt);

        return [
            'unrecorded' => $this->record($resumed) ? null : $resumed,
            'adopted' => true,
            'already' => true,
            'resumed' => true,
            'release' => $id,
            'pre_adopt_path' => $leftover,
            'phase1' => $phases,
        ];
    }

    /** Whether a release attempt verified the current release, so adoption finished. */
    private function verified(string $id): bool
    {
        try {
            return GatewayRelease::query()->where('release_id', $id)->where('outcome', 'verified')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $phases
     */
    private function outcome(string $id, string $sha, string $outcome, array $phases, int $startedAt, ?GatewayReleaseException $exception = null): DeployedGatewayRelease
    {
        $scheduler = $phases['scheduler'] ?? null;

        return new DeployedGatewayRelease(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: 'adopt',
            migrationsRan: false,
            previousId: null,
            snapshotPath: null,
            cleanupPaused: is_array($scheduler) && ($scheduler['cleanup_paused'] ?? false) === true,
            retryable: false,
            durationMs: $this->elapsed($startedAt),
            phases: $phases,
            errorCode: $exception?->errorCode,
            message: $exception?->getMessage(),
        );
    }

    /**
     * Puts the checkout directory and the web build back after a failed handoff, verify, web switch, or smoke, repeats the handoff
     * from it, records the attempt, and returns the failure to throw.
     *
     * @param  array<string, mixed>  $phases
     */
    private function switchBack(GatewayReleaseException $exception, string $current, string $kept, string $id, string $sha, array $phases, int $startedAt, bool $scheduled): GatewayReleaseException
    {
        $phases[$exception->step] = [...$exception->phase, 'outcome' => 'failed', 'error_code' => $exception->errorCode];
        $outcome = 'switched_back';

        try {
            $this->files->restore($this->layout, $id, substr($kept, -16));
            $environment = $this->files->restoreEnvironment($this->layout);
            // The checkout may predate the handoff command, so this process's code hands the runtime back to it.
            $back = ['outcome' => 'switched_back', 'to' => $current, 'environment' => $environment, 'handoff' => $this->runtime->handoff($id)];

            if ($scheduled) {
                $back['scheduler'] = $this->runtime->schedule($id);
            }

            $phases['switch_back'] = $back;
        } catch (Throwable $thrown) {
            $outcome = 'failed';
            $back = GatewayReleaseException::fromThrowable($thrown, 'switch_back', $sha);
            $phases['switch_back'] = ['outcome' => 'failed', 'error_code' => $back->errorCode];
            $exception = new GatewayReleaseException(
                step: 'switch',
                errorCode: 'gateway.release_switch_back_failed',
                message: $exception->getMessage().' Switching back to the checkout then failed: '.$back->getMessage(),
                status: 500,
                previous: $back,
                sha: $sha,
            );
        }

        $this->record($this->outcome($id, $sha, $outcome, $phases, $startedAt, $exception));

        return $exception;
    }

    /** Renames a directory a swap left at `orbit.adopt-next` when adoption stopped right after it. */
    private function finishLeftover(): ?string
    {
        return $this->files->finish($this->layout, ($this->clock)());
    }

    /** Writes the record, and says whether it could; a failed write must not undo the adoption. */
    private function record(DeployedGatewayRelease $release): bool
    {
        try {
            $this->recorder->write($release);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function elapsed(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
