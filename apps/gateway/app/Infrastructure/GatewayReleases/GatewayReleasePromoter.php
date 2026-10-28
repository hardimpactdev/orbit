<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Models\GatewayRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Switches to a prepared release, hands the runtime over, verifies, switches the web app, then
 * runs smoke. Verify and smoke see the new release. Smoke runs only after the web switch, and it
 * checks that the scheduler and agent view started after the handoff began.
 *
 * Any failure after the switch, expected or not, is handled the same way: without migrations the
 * previous release becomes current again and its runtime handoff repeats; after migrations the
 * release pauses. A failure after migrations always pauses, also when the switch itself failed,
 * because the previous code then serves a schema it has not run. Every outcome is recorded, and
 * each step is stored on the release record as it ends.
 */
final readonly class GatewayReleasePromoter
{
    /**
     * Pruning removes a release directory without `REVISION` once it is this many seconds old. Every prepare runs
     * under the release lock that pruning holds too, so no prepare writes to it; the age is a margin on top.
     */
    public const int IncompleteReleaseSeconds = 3600;

    /** The command `orbit-fleet-converge.service` runs, relative to a release's Gateway application. */
    public const string FleetCommand = 'app/Console/Commands/FleetConvergeCommand.php';

    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseSwitcher $switcher,
        private GatewayReleaseRuntime $runtime,
        private GatewayReleaseVerifier $verifier,
        private GatewayReleaseWebBuild $web,
        private GatewayReleaseSmoke $smoke,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseBuilder $builder,
        private int $keptReleases,
        private GatewayReleaseRetry $retry = new GatewayReleaseRetry,
        private ?FleetConvergeUnits $fleet = null,
    ) {}

    /**
     * @param  array<string, mixed>  $phases
     */
    public function promote(
        string $id,
        string $sha,
        string $trigger,
        bool $migrationsRan,
        ?string $snapshotPath,
        array $phases,
        int $startedAt,
        ?GatewayRelease $record = null,
        bool $held = false,
        bool $finalize = false,
    ): DeployedGatewayRelease {
        $previous = $finalize ? $record?->previous_release_id : $this->layout->currentReleaseId();
        $scheduled = null;
        $scheduleStarted = false;
        $step = 'configuration';

        try {
            if (! isset($phases['configuration'])) {
                $this->builder->refreshConfiguration($id);
                $phases['configuration'] = ['outcome' => 'cached'];
            }

            $this->recorder->progress($record, $phases, $sha);

            if (! $finalize) {
                $step = 'switch';
                $previous = $this->switcher->switchTo($id);
                $phases['switch'] = ['outcome' => 'switched', 'from' => $previous, 'to' => $id];
                $this->recorder->progress($record, $phases);
            }
            if ($held) {
                $release = $this->result(
                    id: $id, sha: $sha, outcome: 'paused', trigger: $trigger,
                    migrationsRan: $migrationsRan, previousId: $previous, snapshotPath: $snapshotPath,
                    cleanupPaused: true, startedAt: $startedAt,
                    phases: [...$phases, 'hold' => ['outcome' => 'held', 'record' => $record?->id, 'source' => $previous, 'source_sha' => $previous === null ? null : $this->layout->preparedCommit($previous)]],
                    errorCode: 'gateway.release_held',
                    message: 'The release is staged. Consumers remain held; verification and resume are operator actions.',
                );
                // Unlike a best-effort verified record, a held release must durably record its pause.
                $this->recorder->write($release, $record);

                return $release;
            }
            $step = 'handoff';
            $handoffAt = CarbonImmutable::now('UTC');
            $phases['handoff'] = $this->runtime->handoff($id);
            $this->recorder->progress($record, $phases);
            $step = 'verify';
            $verified = $this->verifier->verify($sha);
            $phases['verify'] = ['outcome' => 'passed', 'status' => $verified['status'], 'version' => $verified['version']];
            $this->recorder->progress($record, $phases);
            $step = 'scheduler';
            // The scheduler may restart on the new release before a later part of this phase fails.
            $scheduleStarted = true;
            $scheduled = $this->runtime->schedule($id);
            $phases['scheduler'] = $scheduled;
            $this->recorder->progress($record, $phases);
            $step = 'web';
            $phases['web'] = $this->publishWeb($id, $sha, $trigger);
            $this->recorder->progress($record, $phases);
            $step = 'smoke';
            $phases['smoke'] = $this->smoke->run($id, $sha, $handoffAt, $phases['web']['outcome'] === 'kept' ? ['web'] : []);
        } catch (Throwable $exception) {
            $this->fail(
                exception: GatewayReleaseException::fromThrowable($exception, $step, $sha),
                id: $id,
                sha: $sha,
                trigger: $trigger,
                migrationsRan: $migrationsRan,
                snapshotPath: $snapshotPath,
                phases: $phases,
                startedAt: $startedAt,
                previous: $previous,
                scheduled: $scheduled,
                scheduleStarted: $scheduleStarted,
                record: $record,
            );
        }

        $release = $this->result(
            id: $id,
            sha: $sha,
            outcome: 'verified',
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: ($scheduled['cleanup_paused'] ?? false) === true,
            startedAt: $startedAt,
            phases: $phases,
        );
        if ($finalize) {
            $this->recorder->write($release, $record);
        } else {
            $this->record($release, $record);
        }
        $this->prune($id, $previous);
        $this->followFleet($id, $sha, $phases['verify']);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $phases
     * @param  array<string, mixed>|null  $scheduled  the schedule phase's result, when the scheduler already moved
     */
    private function fail(
        GatewayReleaseException $exception,
        string $id,
        string $sha,
        string $trigger,
        bool $migrationsRan,
        ?string $snapshotPath,
        array $phases,
        int $startedAt,
        ?string $previous,
        ?array $scheduled,
        bool $scheduleStarted,
        ?GatewayRelease $record,
    ): never {
        $outcome = 'failed';
        $cleanupPaused = ($scheduled['cleanup_paused'] ?? false) === true;
        $phases[$exception->step] = [...$exception->phase, 'outcome' => 'failed', 'error_code' => $exception->errorCode];
        $switched = $previous !== $id && $this->layout->currentReleaseId() === $id;
        $this->recorder->progress($record, $phases);

        if ($migrationsRan) {
            // The migrator may have changed the schema; recovery is an operator decision.
            $outcome = 'paused';
            $phases['pause'] = array_filter([
                'outcome' => 'paused',
                'snapshot' => $snapshotPath,
                'current' => $this->layout->currentReleaseId(),
            ], static fn (mixed $value): bool => $value !== null);
        } elseif ($switched && $previous !== null) {
            try {
                $this->switcher->switchTo($previous);
                $this->web->restore($previous);
                $back = ['outcome' => 'switched_back', 'to' => $previous, 'handoff' => $this->runtime->handoff($previous)];

                if ($scheduleStarted) {
                    $back['scheduler'] = $this->runtime->schedule($previous);
                    $cleanupPaused = ($back['scheduler']['cleanup_paused'] ?? false) === true;
                }

                $phases['switch_back'] = $back;
                $outcome = 'switched_back';
            } catch (Throwable $thrown) {
                $back = GatewayReleaseException::fromThrowable($thrown, 'switch_back', $sha);
                $phases['switch_back'] = ['outcome' => 'failed', 'error_code' => $back->errorCode, 'current' => $this->layout->currentReleaseId()];
                $exception = new GatewayReleaseException(
                    step: 'switch',
                    errorCode: 'gateway.release_switch_back_failed',
                    message: $exception->getMessage().' Switch-back then failed: '.$back->getMessage(),
                    status: 500,
                    previous: $back,
                    sha: $sha,
                );
            }
        }

        $this->record($this->result(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            startedAt: $startedAt,
            phases: $phases,
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
            retryable: $outcome !== 'paused' && $this->retry->retryable($exception->errorCode, $sha, $record?->id),
        ), $record);

        throw $exception;
    }

    /**
     * Writes the record without letting a failed write change the outcome: a verified release is
     * not switched back because its row could not be stored.
     */
    private function record(DeployedGatewayRelease $release, ?GatewayRelease $record = null): void
    {
        try {
            $this->recorder->write($release, $record);
        } catch (Throwable $exception) {
            Log::error('The Gateway release record could not be written.', [
                'release' => $release->toArray(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $phases
     */
    private function result(
        string $id,
        string $sha,
        string $outcome,
        string $trigger,
        bool $migrationsRan,
        ?string $previousId,
        ?string $snapshotPath,
        bool $cleanupPaused,
        int $startedAt,
        array $phases,
        ?string $errorCode = null,
        ?string $message = null,
        bool $retryable = false,
    ): DeployedGatewayRelease {
        return new DeployedGatewayRelease(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previousId,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            retryable: $retryable,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            phases: $phases,
            errorCode: $errorCode,
            message: $message,
        );
    }

    /**
     * The fleet follows a verified release (ADR 0202). The rollout runs in its own unit from the new release, and
     * starting it never fails or rolls back this release. It starts only when the release has a desired fleet
     * state: the Gateway serves the release's exact commit, not `dev`, and the release ships the rollout command.
     * Adopt's phase 1 never comes here; it records its own outcome.
     *
     * @param  array<string, mixed>  $verify
     */
    private function followFleet(string $id, string $sha, array $verify): void
    {
        if (! $this->fleet instanceof FleetConvergeUnits) {
            return;
        }

        $version = is_string($verify['version'] ?? null) ? strtolower(trim($verify['version'])) : '';
        $exact = GatewayReleaseCommit::isSha($sha)
            && ($version === strtolower($sha) || $version === GatewayReleaseCommit::id($sha));

        if (! $exact || ! is_file($this->layout->releaseApplicationPath($id).'/'.self::FleetCommand)) {
            Log::info('The fleet rollout does not follow this release: it has no desired fleet state.', ['release' => $id, 'version' => $version]);

            return;
        }

        $this->fleet->start();
    }

    /**
     * Switches the web app to the release's build. A deploy fails without that build. A rollback is often an
     * emergency, so it installs a missing build from CI first, and when CI no longer has it, keeps the web app as it
     * is and continues with a warning instead of blocking the code rollback on assets.
     *
     * @return array{outcome: string, installed?: bool, warning?: string, error_code?: string}
     */
    private function publishWeb(string $id, string $sha, string $trigger): array
    {
        try {
            $this->web->publish($id);

            return ['outcome' => 'published'];
        } catch (GatewayReleaseException $exception) {
            if ($trigger !== 'rollback' || $exception->errorCode !== 'gateway.release_web_build_missing') {
                throw $exception;
            }
        }

        try {
            $this->web->install($id, $sha);
            $this->web->publish($id);

            return ['outcome' => 'published', 'installed' => true];
        } catch (GatewayReleaseException $exception) {
            return [
                'outcome' => 'kept',
                'warning' => 'The web build of the rollback target is not available, so the web app stays as it was: '.$exception->getMessage(),
                'error_code' => $exception->errorCode,
            ];
        }
    }

    /**
     * Keeps the current and the previous release, whatever their age, and fills the rest of the kept count with the
     * newest other releases. It also removes the release directories that a stopped prepare left without `REVISION`.
     */
    private function prune(string $current, ?string $previous): void
    {
        try {
            $inventory = $this->layout->observe();
        } catch (GatewayReleaseException) {
            Log::warning('Gateway release skipped pruning: the release inventory cannot be read.');

            return;
        }
        $ids = $inventory->retained;
        $protected = array_values(array_filter([$inventory->current, $current, $previous]));
        $keep = [
            ...$protected,
            ...array_slice(array_values(array_unique([...array_intersect($protected, $ids), ...$ids])), 0, max(2, $this->keptReleases)),
        ];

        foreach ($ids as $id) {
            if (in_array($id, $keep, true)) {
                continue;
            }

            try {
                $this->builder->remove($id);
            } catch (Throwable) {
                // Pruning is not the release. A release that cannot be removed stays until the next one.
            }
        }

        try {
            $inventory = $this->layout->observe();
            $this->pruneIncomplete([$current, $previous, $inventory->current], $inventory->incomplete);
            $retained = $this->layout->observe()->retained;
        } catch (GatewayReleaseException) {
            Log::warning('Gateway release skipped pruning web builds: the release inventory cannot be read.');

            return;
        }

        // A verified release is retained, so an empty list means the releases directory could not be read. Pruning
        // the web builds against it would remove every build but the current one.
        if ($retained === []) {
            Log::warning('Gateway release skipped pruning web builds: the releases directory cannot be read.', ['releases' => $this->layout->releasesPath()]);

            return;
        }

        try {
            $this->web->prune($retained);
        } catch (Throwable) {
            // A web build that cannot be removed now is removed by a later release.
        }
    }

    /**
     * Removes each release directory without `REVISION` that is older than IncompleteReleaseSeconds, and its web
     * build. The current and the previous release stay, also when they are incomplete, because they need a repair,
     * not a removal.
     *
     * @param  list<string|null>  $kept
     * @param  array<string, int>  $incomplete
     */
    private function pruneIncomplete(array $kept, array $incomplete): void
    {
        foreach ($incomplete as $id => $modified) {
            $id = (string) $id;
            if (in_array($id, $kept, true) || $modified > time() - self::IncompleteReleaseSeconds) {
                continue;
            }

            try {
                $this->builder->remove($id);
            } catch (Throwable $exception) {
                // Pruning is not the release. A directory that cannot be removed stays until the next one.
                Log::warning('Gateway release could not prune an incomplete release directory.', [
                    'release' => $id,
                    'error_code' => $exception instanceof GatewayReleaseException ? $exception->errorCode : null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
