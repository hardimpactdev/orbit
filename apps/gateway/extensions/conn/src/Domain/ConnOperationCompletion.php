<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Orbit\Extensions\Conn\Models\ConnPairing;

final readonly class ConnOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        if ($operation->kind === 'conn.gateway_sessions_revoke') {
            return;
        }
        if ($operation->kind === 'conn.pairing_create') {
            $pairing = ConnPairing::query()->findOrFail($operation->target_id);
            if ($pairing->credential === null || $pairing->pairing_link_id !== ($outcome->result['pairing_link_id'] ?? null)) {
                throw new ResourceOperationException('operation.completion_unproven', 'The issued pairing credential has not been stored.', 409);
            }

            return;
        }
        ConnPairing::query()->whereIn('id', $operation->input['pairing_ids'])
            ->where('conn_environment_id', $operation->input['conn_environment_id'])
            ->where('node_id', $operation->target_id)->update(['revoked_at' => Carbon::now(), 'credential' => null]);
    }
}
