<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\Push\ConnPushDevices;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

final readonly class ConnOperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->kind === 'conn.pairing_revoke') {
            app(ConnHeldSessions::class)->revoked($operation, $outcome);
        }
        if ($operation->kind === 'conn.gateway_sessions_revoke') {
            if ($outcome->status === OperationStatus::Succeeded) {
                ConnEnvironment::query()->whereKey($operation->target_id)
                    ->where('cleanup_version', $operation->input['cleanup_version'])
                    ->update(['cleanup_completed_version' => $operation->input['cleanup_version']]);
            }
            // The cleanup held the server; an interrupted one runs again, and a revoke it kept waiting starts (/reference/conn#owed-work).
            if (in_array($outcome->status, [OperationStatus::Succeeded, OperationStatus::Interrupted], true)) {
                $environment = $operation->target_id;
                DB::afterCommit(static fn () => app(ConnOwedWork::class)->advance($environment));
            }

            return;
        }
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        ConnPairing::query()->whereIn('id', $operation->input['pairing_ids'])
            ->where('conn_environment_id', $operation->input['conn_environment_id'])
            ->where('node_id', $operation->target_id)->update(['revoked_at' => Carbon::now(), 'credential' => null]);
        app(ConnPushDevices::class)->removeIfNoLivePairings($operation->target_id);
    }
}
