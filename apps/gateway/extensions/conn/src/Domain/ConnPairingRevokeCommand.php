<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Domain\Operations\OperationGatewayCommand;
use Orbit\Extensions\Conn\Models\ConnPairing;
use SensitiveParameter;

/** Revocation is convergent: both an already consumed link and a gone session remain revoked. */
final readonly class ConnPairingRevokeCommand implements OperationGatewayCommand
{
    public function __construct(private T3ServerClient $server) {}

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['environment', 'pairing_ids'],
            'properties' => ['environment' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'admin_session', 'id'],
                'properties' => ['url' => ['type' => 'string'], 'admin_session' => ['type' => 'string'], 'id' => ['type' => 'integer']]],
                'pairing_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 10000]]];
    }

    /** @param array{environment: array{url: string, admin_session: string, id: int}, pairing_ids: list<int>} $input
     * @return array{backend: string, revoked_sessions: int}
     */
    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        if ($operation->kind !== 'conn.pairing_revoke') {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not a pairing revocation.', 422);
        }
        $pairings = ConnPairing::query()->whereIn('id', $input['pairing_ids'])
            ->where('node_id', $operation->target_id)->where('conn_environment_id', $input['environment']['id'])->get();
        if ($pairings->count() !== count($input['pairing_ids'])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The revocation names another Node pairing.', 422);
        }
        $count = 0;
        if ($pairings->isNotEmpty()) {
            $labels = $pairings->pluck('client_label')->all();
            foreach ($this->server->clientSessions($input['environment']['url'], $input['environment']['admin_session']) as $session) {
                if (! $session->current && in_array($session->label, $labels, true)
                    && $this->server->revokeClientSession($input['environment']['url'], $input['environment']['admin_session'], $session->sessionId)) {
                    $count++;
                }
            }
            foreach ($pairings as $pairing) {
                if ($pairing->pairing_link_id !== null) {
                    $this->server->revokePairingLink($input['environment']['url'], $input['environment']['admin_session'], $pairing->pairing_link_id);
                }
            }
        }

        return ['backend' => 'revoked', 'revoked_sessions' => $count];
    }
}
