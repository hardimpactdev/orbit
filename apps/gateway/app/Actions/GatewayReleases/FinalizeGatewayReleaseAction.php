<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\GatewayReleases\GatewayReleaseHold;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Models\GatewayRelease;

/** Completes one staged record; never snapshots, migrates or switches source. */
final readonly class FinalizeGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseExecutionOwner $owner,
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private GatewayReleaseRecovery $recovery,
        private GatewayReleasePromoter $promoter,
        private GatewayReleaseCommands $commands,
    ) {}

    public function execute(string $sha, int $recordId): DeployedGatewayRelease
    {
        $this->owner->assertAllowed($sha);

        return $this->lock->run(function () use ($sha, $recordId): DeployedGatewayRelease {
            $this->owner->assertAllowed($sha);
            $record = GatewayRelease::query()->find($recordId);
            if (! GatewayReleaseCommit::isSha($sha) || ! $record instanceof GatewayRelease || $record->sha !== $sha
                || data_get($record->phases, 'hold.outcome') !== 'held'
                || data_get($record->phases, 'hold.record') !== $record->id
                || data_get($record->phases, 'hold.source') !== $record->previous_release_id
                || ! in_array($record->outcome, ['paused', 'verified'], true)
                || $record->release_id === null || $record->previous_release_id === null || $record->previous_release_id === $record->release_id
                || $this->layout->preparedCommit($record->previous_release_id) !== data_get($record->phases, 'hold.source_sha')
                || $this->layout->currentReleaseId() !== $record->release_id
                || $this->layout->preparedCommit($record->release_id) !== $sha) {
                throw new GatewayReleaseException('finalize', 'gateway.release_finalize_invalid', 'Finalize requires the original staged record, full SHA and current prepared target.', 422);
            }
            $this->commands->checkMigrations($this->layout, $record->release_id, $this->lock->stepPath());
            if ($record->outcome === 'verified') {
                GatewayReleaseHold::assertNone();
                $this->recovery->verify($record);

                return $this->result($record);
            }
            $id = $record->release_id;
            $recovery = $this->recovery->protect($record);
            // Strict persistence precedes runtime handoff and any clearing of the pause marker.
            $record->forceFill(['snapshot_path' => $recovery['path'], 'phases' => [...$record->phases, 'recovery' => $recovery]])->saveOrFail();

            return $this->promoter->promote(
                id: $id, sha: $sha, trigger: 'deploy', migrationsRan: true,
                snapshotPath: $recovery['path'], phases: $record->phases, startedAt: hrtime(true),
                record: $record, finalize: true,
            );
        });
    }

    private function result(GatewayRelease $record): DeployedGatewayRelease
    {
        if ($record->release_id === null || $record->sha === null) {
            throw new GatewayReleaseException('finalize', 'gateway.release_finalize_invalid', 'The staged record lost its identity.');
        }

        return new DeployedGatewayRelease(
            id: $record->release_id, sha: $record->sha, outcome: $record->outcome, trigger: $record->trigger,
            migrationsRan: $record->migrations_ran, previousId: $record->previous_release_id,
            snapshotPath: $record->snapshot_path, cleanupPaused: $record->cleanup_paused,
            retryable: $record->retryable, durationMs: $record->duration_ms, phases: $record->phases,
            errorCode: $record->error_code, message: $record->message,
        );
    }
}
