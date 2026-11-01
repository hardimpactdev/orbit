<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperationResultHook;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Orbit\Extensions\Conn\Actions\RegisterConnEnvironmentAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

/** Safe Conn state and domain result validation. No T3 calls or secret writes. */
final readonly class ConnDirectResult implements DirectOperationResultHook
{
    public function input(Operation $operation): array
    {
        if ($operation->kind === 'conn.pairing_revoke') {
            return Arr::only($operation->input, ['intent', 'execution_secrets']);
        }
        $ids = ValidatedData::integerList($operation->input['pairing_ids'] ?? []);
        $pairings = ConnPairing::query()->whereIn('id', $ids)->where('conn_environment_id', $operation->input['conn_environment_id'])
            ->where('node_id', $operation->input['caller_node_id'] ?? null)->get();
        if ($pairings->count() !== count($ids)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted pairing owner changed.', 422);
        }
        if (! $operation->status->terminal() && $operation->kind === 'conn.gateway_sessions_revoke'
            && ConnEnvironment::query()->findOrFail($operation->target_id)->cleanup_version !== $operation->input['cleanup_version']) {
            throw new ResourceOperationException('conn.environment_changed', 'The cleanup revision changed.', 409);
        }

        return ['action' => substr($operation->kind, 5),
            'labels' => [RegisterConnEnvironmentAction::GATEWAY_CLIENT_LABEL],
            'links' => $pairings->pluck('pairing_link_id')->filter()->values()->all()];
    }

    public function validate(Operation $operation, array $result, OperationStatus $status): array
    {
        if ($operation->status->terminal()) {
            return $result;
        }
        if ($result === [] && $status !== OperationStatus::Succeeded) {
            return [];
        }
        if (array_keys($result) === ['error_code'] && is_string($result['error_code']) && (preg_match('/\Aconn\.[a-z_]{1,80}\z/D', $result['error_code']) === 1 || in_array($result['error_code'], ['operation.native_dispatch_failed', 'operation.native_failed'], true))) {
            return $result;
        }
        if ($operation->kind !== 'conn.pairing_create') {
            if (count($result) !== 2 || ($result['backend'] ?? null) !== 'revoked' || ! is_int($result['revoked_sessions'] ?? null) || $result['revoked_sessions'] < 0) {
                $this->refuse();
            }

            return $result;
        }
        $this->refuse();
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        app(ConnOperationCompletion::class)->complete($operation, $outcome);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.result_invalid', 'The Conn result does not match its intent.', 422);
    }
}
