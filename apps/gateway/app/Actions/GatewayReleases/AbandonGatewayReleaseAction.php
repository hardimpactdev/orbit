<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AbandonGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseExecutionOwner $owner,
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private GatewayReleaseDrain $drain,
        private GatewayReleaseRestore $restore,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseAutomation $automation,
    ) {}

    /** @return array{outcome: string, sha: string, record: int, source: string|null, snapshot: mixed} */
    public function execute(string $sha, int $id): array
    {
        $this->owner->assertAllowed($sha);
        if (! GatewayReleaseCommit::isSha($sha)) {
            throw new GatewayReleaseException('abandon', 'gateway.release_abandon_invalid', 'Abandon requires the full staged SHA.', 422);
        }

        return $this->lock->run(function () use ($sha, $id): array {
            $this->owner->assertAllowed($sha);
            $receipt = $this->restore->read($sha, $id);
            $source = $receipt->previous_release_id;
            $markerPath = $this->recorder->pauseMarkerPath();
            $marker = json_decode((string) @file_get_contents($markerPath), true);
            if ($source === null || $this->layout->currentReleaseId() !== $source
                || $this->layout->preparedCommit($source) !== data_get($receipt->phases, 'hold.source_sha')
                || ! is_array($marker) || ($marker['sha'] ?? null) !== $sha || ($marker['staged_record'] ?? null) !== $id
                || $this->automation->enabled()) {
                throw new GatewayReleaseException('abandon', 'gateway.release_abandon_invalid', 'Abandon requires the exact source, staged marker and disabled automatic releases. Keep consumers stopped.', 422);
            }
            $this->drain->assertHeld($sha);
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                $this->restore->assertRestored($pdo, $receipt);
                $connection->table('gateway_releases')->where('id', $id)->update($this->restore->abandoned($receipt));
                $pdo->exec('COMMIT');
            } catch (Throwable $exception) {
                $pdo->exec('ROLLBACK');
                throw $exception;
            }
            if (! unlink($markerPath)) {
                throw new GatewayReleaseException('abandon', 'gateway.release_abandon_incomplete', 'The restored record is abandoned but its marker remains. Repeat this exact abandon command.');
            }

            return ['outcome' => 'abandoned', 'sha' => $sha, 'record' => $id, 'source' => $source, 'snapshot' => data_get($receipt->phases, 'recovery.path')];
        });
    }
}
