<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\GatewayReleases\GatewayReleaseHold;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Models\GatewayRelease;
use Throwable;

/**
 * Prepares a commit, checks that it moves forward and knows the applied schema, caches its
 * configuration, migrates when it has pending migrations, then promotes it. Every attempt that
 * names a commit is recorded; {@see GatewayReleaseRetry} decides whether it may be tried again.
 * A refusal by the guard ends the record without marking the commit failed. A failed migration pauses.
 *
 * The record exists from the start of the attempt and stores each step as it ends, so a caller
 * can follow it ([Release records](/reference/gateway-recovery#release-records)). A verified manual
 * deploy also records whether a newer green commit existed, which decides whether automatic
 * releases pause ({@see GatewayReleaseSupersession}).
 */
final readonly class DeployGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseBuilder $builder,
        private GatewayReleaseDatabase $database,
        private GatewayReleasePromoter $promoter,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseGuard $guard,
        private GatewayReleaseRetry $retry,
        private GatewayReleaseSupersession $supersession,
        private GatewayReleaseExecutionOwner $owner,
        private ?GatewayReleaseDrain $drain = null,
    ) {}

    /**
     * @param  bool  $force  switch even when the commit does not descend from the current one or does not know an applied migration
     * @param  string  $trigger  `deploy` for a manual release, `auto` for the automatic runner
     * @param  GatewayRelease|null  $record  a queued record to claim, or null to start a new one
     */
    public function execute(string $commit, bool $force = false, string $trigger = 'deploy', ?GatewayRelease $record = null): DeployedGatewayRelease
    {
        $this->owner->assertAllowed($commit);

        return $this->lock->run(fn (): DeployedGatewayRelease => $this->deploy($commit, $force, $trigger, $record));
    }

    /** Stage under a maintained operator hold; never start consumers or claim verification. */
    public function stage(string $commit): DeployedGatewayRelease
    {
        $this->owner->assertAllowed($commit);
        if (! GatewayReleaseCommit::isSha($commit)) {
            throw new GatewayReleaseException(step: 'resolve', errorCode: 'gateway.release_commit_invalid', message: 'Staging requires the full target SHA.', status: 422);
        }

        return $this->lock->run(function () use ($commit): DeployedGatewayRelease {
            GatewayReleaseHold::assertNone();
            if ($this->guard->source()['source_sha'] === $commit) {
                throw new GatewayReleaseException('hold', 'gateway.release_stage_current', 'The target is already current; staging requires a different release.', 422);
            }
            $this->assertDrained($commit);

            return $this->deploy($commit, false, 'deploy', null, held: true);
        });
    }

    private function assertDrained(string $sha): void
    {
        if (! $this->drain instanceof GatewayReleaseDrain) {
            throw new GatewayReleaseException(step: 'drain', errorCode: 'gateway.release_not_drained', message: 'No drain observer is configured.');
        }
        $this->drain->assertHeld($sha);
    }

    /**
     * The deploy itself, for a caller that already holds the release lock, such as adoption's second phase.
     *
     * @param  string  $trigger  what the release record names as its trigger
     * @param  GatewayRelease|null  $record  a queued record to claim, or null to start a new one
     */
    public function deploy(string $commit, bool $force, string $trigger, ?GatewayRelease $record = null, bool $held = false): DeployedGatewayRelease
    {
        $this->owner->assertAllowed($commit);
        GatewayReleaseHold::assertNone();
        $startedAt = hrtime(true);
        $record = $this->recorder->begin($trigger, $commit, $record, $force);

        try {
            $deployed = $this->attempt($commit, $force, $trigger, $record, $startedAt, $held);
        } catch (Throwable $exception) {
            $this->recorder->fail($record, $exception, retryable: true, durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000));

            throw $exception;
        }

        if ($record instanceof GatewayRelease && $trigger === 'deploy' && $deployed->succeeded()) {
            // Whether this manual deploy pins an older commit on purpose decides if automation pauses.
            $record->refresh();
            $this->recorder->progress($record, [...$record->phases, 'newest_green' => $this->supersession->check($deployed->sha)]);
        }

        return $deployed;
    }

    private function attempt(string $commit, bool $force, string $trigger, ?GatewayRelease $record, int $startedAt, bool $held = false): DeployedGatewayRelease
    {
        $step = 'prepare';
        $prepared = null;
        $phases = [];
        $snapshot = null;
        $migrationsRan = false;

        try {
            $prepared = $this->builder->prepare($commit);
            $phases['prepare'] = ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs];
            $this->recorder->progress($record, $phases, $prepared->sha);
            $step = 'guard';
            $this->guard->assertForward($prepared->sha, $force);
            $phases['guard'] = ['outcome' => 'passed', 'force' => $force];
            // Before any migration: a configuration that cannot be cached must stop the release while nothing changed.
            $step = 'configuration';
            $this->builder->refreshConfiguration($prepared->id);
            $phases['configuration'] = ['outcome' => 'cached'];
            $this->recorder->progress($record, $phases);
            if ($held) {
                $step = 'drain';
                $this->assertDrained($prepared->sha);
                $phases['drain'] = ['outcome' => 'held'];
            }
            $step = 'snapshot';
            $taken = $this->database->snapshot($prepared->id);
            // A retry of a paused commit keeps naming the copy from before its first migration attempt.
            $snapshot = $this->recorder->pausedSnapshot($prepared->sha) ?? $taken;
            $phases['snapshot'] = ['outcome' => 'snapshotted', 'path' => $taken, 'clean' => $snapshot];
            $this->recorder->progress($record, $phases);
            if ($held) {
                $step = 'drain';
                $this->assertDrained($prepared->sha);
            }
            if ($held) {
                $phases['hold'] = ['outcome' => 'held', 'record' => $record?->id, ...$this->guard->source()];
                $this->recorder->stageIntent($record, $prepared->sha, $snapshot, $phases);
            }
            $step = 'migrate';
            $this->database->migrate($prepared->path);
            $migrationsRan = true;
            $phases['migrate'] = ['outcome' => 'migrated'];
            $this->recorder->progress($record, $phases);
            if ($held) {
                // A failed barrier after migration must remain a paused schema change.
                $step = 'drain';
                $this->assertDrained($prepared->sha);
            }
        } catch (Throwable $thrown) {
            $exception = GatewayReleaseException::fromThrowable($thrown, $step, $prepared?->sha);
            $this->recordFailure($exception, $step, $phases, $snapshot, $startedAt, $trigger, $record, $migrationsRan);

            throw $exception;
        }

        return $this->promoter->promote(
            id: $prepared->id,
            sha: $prepared->sha,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            snapshotPath: $snapshot,
            phases: $phases,
            startedAt: $startedAt,
            record: $record,
            held: $held,
        );
    }

    /**
     * A failed migration may have applied part of its changes, so it pauses like a failure after
     * migrations. Without a full commit, and for a refusal by the guard, the record ends failed
     * and retryable: nothing changed and the commit is not marked failed.
     *
     * @param  array<string, mixed>  $phases
     */
    private function recordFailure(GatewayReleaseException $exception, string $step, array $phases, ?string $snapshot, int $startedAt, string $trigger, ?GatewayRelease $record, bool $migrationsRan): void
    {
        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        try {
            if ($exception->sha === null || strlen($exception->sha) !== 40 || $step === 'guard') {
                $this->recorder->fail($record, $exception, retryable: true, durationMs: $durationMs, phase: $step);

                return;
            }

            $migrating = $migrationsRan || $step === 'migrate';
            $this->recorder->write(new DeployedGatewayRelease(
                id: substr($exception->sha, 0, 12),
                sha: $exception->sha,
                outcome: $migrating ? 'paused' : 'failed',
                trigger: $trigger,
                migrationsRan: $migrating,
                previousId: is_string(data_get($phases, 'hold.source')) ? data_get($phases, 'hold.source') : null,
                snapshotPath: $snapshot,
                cleanupPaused: false,
                retryable: ! $migrating && $this->retry->retryable($exception->errorCode, $exception->sha, $record?->id),
                durationMs: $durationMs,
                phases: [...$phases, $step => ['outcome' => 'failed', 'error_code' => $exception->errorCode]],
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            ), $record);
        } catch (Throwable) {
            // The failure itself is what the caller needs; a record that cannot be written must not replace it.
        }
    }
}
