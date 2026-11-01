<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

final readonly class ConnOperationSecrets implements OperationSecretSource
{
    public function name(): string
    {
        return 'conn.environment';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['conn_pairing', 'conn_environment'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $id = $operation->input['conn_environment_id'] ?? null;

        return in_array($operation->kind, ['conn.gateway_sessions_revoke'], true) && is_int($id) ? $id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Conn session does not belong to this Operation.', 422);
        }
        if ($operation->kind === 'conn.gateway_sessions_revoke' && $operation->target_id !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Cleanup has another environment owner.', 422);
        }
        $environment = ConnEnvironment::query()->findOrFail($id);
        if ($operation->kind === 'conn.gateway_sessions_revoke' && $environment->cleanup_version !== $operation->input['cleanup_version']) {
            throw new ResourceOperationException('conn.environment_changed', 'The cleanup revision changed.', 409);
        }

        return new OperationSecretValue(new ConnExecutionInput(['url' => $environment->url, 'admin_session' => $environment->admin_session, 'id' => $environment->id]), [$environment->admin_session]);
    }
}
