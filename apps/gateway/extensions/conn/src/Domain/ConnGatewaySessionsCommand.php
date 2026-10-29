<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\Conn\Actions\RegisterConnEnvironmentAction;

final readonly class ConnGatewaySessionsCommand implements OperationGatewayCommand
{
    public function __construct(private T3ServerClient $server, private ConnPairingRevokeCommand $contract) {}

    public function schema(): array
    {
        $schema = $this->contract->schema();
        $schema['required'] = ['environment', 'pairing_ids', 'session_ids'];
        $schema['properties'] = [
            'environment' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'admin_session', 'id'],
                'properties' => ['url' => ['type' => 'string'], 'admin_session' => ['type' => 'string'], 'id' => ['type' => 'integer']]],
            'pairing_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 0],
            'session_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10000],
        ];

        return $schema;
    }

    /** @param array{environment: ConnExecutionInput, pairing_ids: list<int>, session_ids: list<string>} $input */
    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $environment = $input['environment']->jsonSerialize();
        if ($operation->kind !== 'conn.gateway_sessions_revoke' || $operation->target_id !== $environment['id'] || $input['pairing_ids'] !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Gateway session cleanup has another owner.', 422);
        }
        $count = 0;
        foreach ($this->server->clientSessions($environment['url'], $environment['admin_session']) as $session) {
            if (! $session->current && $session->label === RegisterConnEnvironmentAction::GATEWAY_CLIENT_LABEL && in_array($session->sessionId, $input['session_ids'], true)
                && $this->server->revokeClientSession($environment['url'], $environment['admin_session'], $session->sessionId)) {
                $count++;
            }
        }

        return ['backend' => 'revoked', 'revoked_sessions' => $count];
    }
}
