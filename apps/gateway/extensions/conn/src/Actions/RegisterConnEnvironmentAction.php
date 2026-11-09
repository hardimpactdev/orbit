<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Data\RegisterConnEnvironmentData;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Registers a T3 server. The Gateway checks that the URL serves the named environment and that T3 accepts
 * the admin session the server's host issued, then stores that session encrypted. Registering again from
 * the same Node replaces the session and records a revision for Swamp to clean earlier Gateway sessions.
 */
final readonly class RegisterConnEnvironmentAction
{
    /** The client label of the Gateway's own admin session on every T3 server. */
    public const string GATEWAY_CLIENT_LABEL = 'Orbit Gateway';

    /** The scopes the Gateway needs to mint and revoke device sessions. */
    private const array REQUIRED_SCOPES = ['access:read', 'access:write'];

    public function __construct(private T3ServerClient $server, private NodeAccessAuthorizer $access) {}

    public function execute(Node $node, RegisterConnEnvironmentData $data): ConnEnvironment
    {
        // The transaction below refuses any change to this row since this read, so the owner check holds until the write.
        $prior = ConnEnvironment::query()->where('environment_id', $data->environmentId)->first();
        $this->assertMayRegister($node, $prior);
        $revision = $prior?->cleanup_version;
        $marker = $this->cleanupMarker($prior?->id);
        $descriptor = $this->server->describe($data->url);

        if ($descriptor->environmentId !== $data->environmentId) {
            throw new ResourceOperationException(
                'conn.environment_mismatch',
                "The T3 server at {$data->url} is environment {$descriptor->environmentId}, not {$data->environmentId}.",
                details: ['served_environment_id' => $descriptor->environmentId],
            );
        }

        $session = $this->server->session($data->url, $data->adminSession);
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $session->scopes));
        if ($missing !== []) {
            throw new ResourceOperationException('conn.admin_scope_missing', 'The session is not an admin session. It lacks '.implode(', ', $missing).'.',
                details: ['missing_scopes' => implode(' ', $missing)]);
        }
        $now = Carbon::now();
        $environment = DB::transaction(function () use ($node, $data, $descriptor, $now, $prior, $revision, $marker, $session): ConnEnvironment {
            $environment = ConnEnvironment::query()->where('environment_id', $data->environmentId)->lockForUpdate()->first()
                ?? new ConnEnvironment(['environment_id' => $data->environmentId]);
            if ($this->cleanupMarker($environment->id) !== $marker || $environment->id !== $prior?->id || ($environment->exists ? $environment->cleanup_version : null) !== $revision) {
                throw new ResourceOperationException('conn.environment_changed', 'The environment changed during session validation. Register again.', 409);
            }

            $environment->fill(['label' => $data->label, 'url' => $data->url, 'node_id' => $node->id,
                'server_version' => $descriptor->serverVersion, 'admin_session' => $session->token,
                'admin_session_expires_at' => $session->expiresAt, 'registered_at' => $now,
                'cleanup_version' => $environment->cleanup_version + 1])->save();

            DB::afterCommit(function () use ($environment, $node): void {
                try {
                    app(AdmitConnCleanupAction::class)->execute($environment, $node);
                } catch (ResourceOperationException $error) {
                    Log::warning('conn.cleanup_start_failed', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
                }
            });

            return $environment;
        });

        return $environment;
    }

    /**
     * A server stays with the Node that registered it. Only that Node registers it again; a server whose Node
     * was removed needs Gateway access, so no other Node can take a known environment id. The server of a container
     * Instance is registered by its Instance's flow, so no Node registers it itself.
     */
    private function assertMayRegister(Node $node, ?ConnEnvironment $prior): void
    {
        if ($prior?->instance_id !== null) {
            throw new ResourceOperationException('conn.environment_owned', "Environment {$prior->environment_id} is the T3 server of Instance {$prior->instance_id}; its Instance's flow registers it.", 409);
        }
        if ($prior === null || $prior->node_id === $node->id
            || ($prior->node_id === null && $this->access->hasGatewayAuthority($node))) {
            return;
        }

        throw new ResourceOperationException('conn.environment_owned', "Another Node registered environment {$prior->environment_id}.", 409);
    }

    /** A revocation during validation may revoke the candidate; never store that stale candidate. */
    private function cleanupMarker(?int $environmentId): ?int
    {
        if ($environmentId === null) {
            return null;
        }
        if (Operation::query()->where('lock_key', OperationStore::lockKey('conn_environment', $environmentId))->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'A Conn operation holds this environment.', 409);
        }

        $marker = Operation::query()->whereIn('kind', ['conn.gateway_sessions_revoke', 'conn.pairing_revoke'])
            ->where('input->conn_environment_id', $environmentId)->max('id');

        return $marker === null ? null : ValidatedData::integer($marker);
    }
}
