<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\DirectOperationResultHook;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationResultFields;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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
        $pairing = $operation->kind === 'conn.pairing_create' ? $pairings->sole() : null;

        return ['action' => substr($operation->kind, 5),
            'labels' => $operation->kind === 'conn.gateway_sessions_revoke' ? [RegisterConnEnvironmentAction::GATEWAY_CLIENT_LABEL] : $pairings->pluck('client_label')->all(),
            'links' => $pairings->pluck('pairing_link_id')->filter()->values()->all(),
            'pairing_id' => $pairing?->id, 'issue_started' => $pairing?->issue_started_at !== null, 'issued' => $pairing !== null && $pairing->credential !== null,
            'pairing_link_id' => $pairing?->pairing_link_id, 'expires_at' => $pairing?->expires_at?->toIso8601String()];
    }

    public function validate(Operation $operation, array $result, OperationStatus $status): array
    {
        if ($operation->status->terminal()) {
            return $result;
        }
        if ($result === [] && $status !== OperationStatus::Succeeded) {
            return [];
        }
        if (array_keys($result) === ['error_code'] && is_string($result['error_code']) && (preg_match('/\Aconn\.[a-z_]{1,80}\z/D', $result['error_code']) === 1 || $result['error_code'] === 'operation.native_dispatch_failed')) {
            return $result;
        }
        if ($operation->kind !== 'conn.pairing_create') {
            if (count($result) !== 2 || ($result['backend'] ?? null) !== 'revoked' || ! is_int($result['revoked_sessions'] ?? null) || $result['revoked_sessions'] < 0) {
                $this->refuse();
            }

            return $result;
        }
        $store = app(ConnPairingCredentialStore::class);
        if ($store->ownerId($operation) !== $operation->target_id) {
            $this->refuse();
        }
        $pairing = ConnPairing::query()->lockForUpdate()->findOrFail($operation->target_id);
        $known = app(ConnOperationSecrets::class)->resolve($operation, ValidatedData::integer($operation->input['conn_environment_id']), '', '')->strings();
        if ($pairing->credential !== null) {
            $known[] = $pairing->credential;
        }
        foreach ($result as $value) {
            if (is_string($value) && (strlen($value) > OperationResultFields::MaximumStringBytes
                || array_any($known, static fn (string $secret): bool => $secret !== '' && str_contains($value, $secret)))) {
                $this->refuse();
            }
        }
        if ($status === OperationStatus::Running && $result === ['backend' => 'issuing']) {
            $pairing->forceFill(['issue_started_at' => $pairing->issue_started_at ?? now()])->save();

            return $result;
        }
        if (array_diff(array_keys($result), ['backend', 'pairing_id', 'pairing_link_id', 'expires_at']) !== [] || count($result) !== 4
            || ($result['backend'] ?? null) !== 'issued' || $result['pairing_id'] !== $pairing->id || ! is_string($result['pairing_link_id'])
            || preg_match('/\A[a-zA-Z0-9_-]{1,1024}\z/D', $result['pairing_link_id']) !== 1 || ! is_string($result['expires_at'])
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?(?:Z|[+-]\d{2}:\d{2})\z/D', $result['expires_at']) !== 1) {
            $this->refuse();
        }
        if ($pairing->issue_started_at === null || ($pairing->pairing_link_id !== null && $pairing->pairing_link_id !== $result['pairing_link_id'])) {
            $this->refuse();
        }
        if ($status === OperationStatus::Succeeded && ($pairing->expires_at === null || $pairing->expires_at->getTimestamp() !== Carbon::parse($result['expires_at'])->getTimestamp())) {
            $this->refuse();
        }
        if ($status === OperationStatus::Running) {
            $pairing->forceFill(['pairing_link_id' => $result['pairing_link_id'], 'expires_at' => Carbon::parse($result['expires_at'])])->save();
        }

        return $result;
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
