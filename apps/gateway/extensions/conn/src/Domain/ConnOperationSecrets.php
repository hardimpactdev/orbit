<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

final readonly class ConnOperationSecrets implements OperationSecretSource
{
    public function name(): string
    {
        return 'conn.environment';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['conn_pairing', 'conn_pairing_node', 'conn_environment'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $id = $operation->input['conn_environment_id'] ?? null;

        return in_array($operation->kind, ['conn.pairing_create', 'conn.pairing_revoke', 'conn.gateway_sessions_revoke'], true) && is_int($id) ? $id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Conn session does not belong to this Operation.', 422);
        }
        if ($operation->kind === 'conn.pairing_create' && ! ConnPairing::query()->whereKey($operation->target_id)->where('conn_environment_id', $id)->where('node_id', $operation->input['caller_node_id'])->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The pairing has another environment owner.', 422);
        }
        if ($operation->kind === 'conn.pairing_revoke' && $operation->target_id !== ($operation->input['caller_node_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The revoke has another environment owner.', 422);
        }
        if ($operation->kind === 'conn.gateway_sessions_revoke' && $operation->target_id !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The session cleanup has another environment owner.', 422);
        }
        $environment = ConnEnvironment::query()->findOrFail($id);

        return new OperationSecretValue(new ConnExecutionInput(['url' => $environment->url, 'admin_session' => $environment->admin_session, 'id' => $environment->id]), [$environment->admin_session]);
    }
}
