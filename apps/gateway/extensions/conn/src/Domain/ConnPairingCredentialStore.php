<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\Conn\Models\ConnPairing;
use SensitiveParameter;

/** Kit H extracts the one-time output before this owner-checked encrypted write. */
final readonly class ConnPairingCredentialStore implements OperationSecretOutputStore
{
    public function name(): string
    {
        return 'conn.pairing.credential';
    }

    public function targetTypes(): array
    {
        return ['conn_pairing'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if ($operation->kind !== 'conn.pairing_create' || $operation->target_type !== 'conn_pairing'
            || ($operation->input['pairing_ids'] ?? null) !== [$operation->target_id]) {
            return null;
        }

        return ConnPairing::query()->whereKey($operation->target_id)
            ->where('node_id', $operation->input['caller_node_id'] ?? null)
            ->where('conn_environment_id', $operation->input['conn_environment_id'] ?? null)
            ->whereNull('revoked_at')->first()?->id;
    }

    public function store(Operation $operation, int $ownerId, #[SensitiveParameter] mixed $value): void
    {
        if ($this->ownerId($operation) !== $ownerId) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The pairing output has another owner.', 422);
        }
        if (! is_string($value) || $value === '' || strlen($value) > 16384) {
            throw new ResourceOperationException('conn.pairing_credential_invalid', 'The pairing server returned an invalid credential.', 422);
        }
        $pairing = ConnPairing::query()->lockForUpdate()->findOrFail($ownerId);
        if ($pairing->pairing_link_id === null || $pairing->issue_started_at === null
            || ($pairing->credential !== null && ! hash_equals($pairing->credential, $value))) {
            throw new ResourceOperationException('conn.pairing_outcome_unknown', 'The pairing output does not match the admitted issue.', 409);
        }
        $pairing->forceFill(['credential' => $value])->save();
    }
}
