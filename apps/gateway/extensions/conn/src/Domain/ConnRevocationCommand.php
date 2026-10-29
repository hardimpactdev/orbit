<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationResultFields;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Actions\RegisterConnEnvironmentAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use SensitiveParameter;

/** Reads owned targets or makes exactly one idempotent revoke call. Swamp owns iteration. */
final readonly class ConnRevocationCommand implements OperationGatewayCommand
{
    public function __construct(private T3ServerClient $server, private ConnRevocationExecution $execution) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['environment', 'action', 'id'],
            'properties' => ['environment' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['url', 'admin_session', 'id'],
                'properties' => ['url' => ['type' => 'string'], 'admin_session' => ['type' => 'string'], 'id' => ['type' => 'integer']]],
                'action' => ['type' => 'string', 'enum' => ['inspect', 'session', 'link', 'receipt']],
                'id' => ['type' => 'string', 'maxLength' => 1024]]];
    }

    /** @param array{environment:ConnExecutionInput,action:string,id:string} $input */
    public function run(Operation $operation, #[SensitiveParameter] array $input): array
    {
        return DB::transaction(function () use ($operation, $input): array {
            $environment = $input['environment']->jsonSerialize();
            $stored = ConnEnvironment::query()->lockForUpdate()->findOrFail($environment['id']);
            if ($stored->url !== $environment['url'] || ! hash_equals($stored->admin_session, $environment['admin_session'])
                || ($operation->kind === 'conn.gateway_sessions_revoke' && $stored->cleanup_version !== $operation->input['cleanup_version'])) {
                return ['error_code' => 'conn.environment_changed'];
            }

            return $this->effect($operation, $input, $environment);
        });
    }

    /** @param array{environment:ConnExecutionInput,action:string,id:string} $input
     * @param  array{url:string,admin_session:string,id:int}  $environment
     * @return array<string,mixed>
     */
    private function effect(Operation $operation, #[SensitiveParameter] array $input, #[SensitiveParameter] array $environment): array
    {
        $gateway = $operation->kind === 'conn.gateway_sessions_revoke';
        if (! $gateway && $operation->kind !== 'conn.pairing_revoke') {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not Conn revocation.', 422);
        }
        $pairings = $gateway ? collect() : ConnPairing::query()->whereIn('id', ValidatedData::integerList($operation->input['pairing_ids']))
            ->where('node_id', $operation->target_id)->where('conn_environment_id', $environment['id'])->get();
        if (! $gateway && $pairings->count() !== count(ValidatedData::integerList($operation->input['pairing_ids']))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted pairing owner changed.', 422);
        }
        $labels = $gateway ? [RegisterConnEnvironmentAction::GATEWAY_CLIENT_LABEL] : $pairings->pluck('client_label')->all();
        if ($input['action'] === 'receipt') {
            return $this->execution->result($operation);
        }
        if ($input['action'] === 'link') {
            if (! $pairings->contains('pairing_link_id', $input['id'])) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The admitted link owner changed.', 422);
            }

            return ['backend' => 'revoked', 'revoked' => $this->server->revokePairingLink($environment['url'], $environment['admin_session'], $input['id'])];
        }
        $sessions = $labels === [] ? [] : $this->server->clientSessions($environment['url'], $environment['admin_session']);
        if ($input['action'] === 'session') {
            $session = array_find($sessions, static fn (T3ClientSession $session): bool => $session->sessionId === $input['id']);
            if ($session !== null && ! in_array($session->label, $labels, true)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The admitted session owner changed.', 422);
            }

            return ['backend' => 'revoked', 'revoked' => $session !== null && ! $session->current
                && $this->server->revokeClientSession($environment['url'], $environment['admin_session'], $input['id'])];
        }
        $ids = array_map(static fn (T3ClientSession $session): string => $session->sessionId,
            array_values(array_filter($sessions, static fn (T3ClientSession $session): bool => ! $session->current && in_array($session->label, $labels, true))));
        $links = $pairings->map(static fn (ConnPairing $pairing): ?string => $pairing->pairing_link_id)->filter()->values()->all();
        $result = ['backend' => 'inspected', 'sessions' => array_values(array_unique($ids)), 'links' => array_values(array_unique($links))];

        // Apply the shared bounded-result validator before any plan is recorded or effect can run.
        return OperationResultFields::filter(ConnRevocationExecution::ResultFields, $result);
    }
}
