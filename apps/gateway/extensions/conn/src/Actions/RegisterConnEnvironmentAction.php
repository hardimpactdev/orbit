<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Orbit\Extensions\Conn\Data\RegisterConnEnvironmentData;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Domain\T3ServerException;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Registers a T3 server. The Gateway checks that the URL serves the named environment and that T3 accepts
 * the admin session the server's host issued, then stores that session encrypted. Registering again
 * replaces the session and revokes the Gateway's earlier sessions there.
 */
final readonly class RegisterConnEnvironmentAction
{
    /** The client label of the Gateway's own admin session on every T3 server. */
    public const string GATEWAY_CLIENT_LABEL = 'Orbit Gateway';

    /** The scopes the Gateway needs to mint and revoke device sessions. */
    private const array REQUIRED_SCOPES = ['access:read', 'access:write'];

    public function __construct(private T3ServerClient $server) {}

    public function execute(Node $node, RegisterConnEnvironmentData $data): ConnEnvironment
    {
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
            throw new ResourceOperationException(
                'conn.admin_scope_missing',
                'The session is not an admin session. It lacks '.implode(', ', $missing).'.',
                details: ['missing_scopes' => implode(' ', $missing)],
            );
        }

        $now = Carbon::now();
        $environment = ConnEnvironment::query()->updateOrCreate(
            ['environment_id' => $data->environmentId],
            [
                'label' => $data->label,
                'url' => $data->url,
                'node_id' => $node->id,
                'server_version' => $descriptor->serverVersion,
                'admin_session' => $session->token,
                'admin_session_expires_at' => $session->expiresAt,
                'registered_at' => $now,
            ],
        );

        $this->revokeEarlierGatewaySessions($environment);

        return $environment;
    }

    /** Each restart registers again; earlier admin sessions would otherwise stay valid for 30 days. */
    private function revokeEarlierGatewaySessions(ConnEnvironment $environment): void
    {
        try {
            $ids = [];
            foreach ($this->server->clientSessions($environment->url, $environment->admin_session) as $session) {
                if (! $session->current && $session->label === self::GATEWAY_CLIENT_LABEL) {
                    $ids[] = $session->sessionId;
                }
            }
            if ($ids === []) {
                return;
            }
            app(StartOperationAction::class)->execute('conn.gateway_sessions_revoke', 'conn_environment', $environment->id, $environment->node_id,
                ['conn_environment_id' => $environment->id, 'environment' => ['$secret' => 'conn.environment', 'id' => $environment->id], 'pairing_ids' => [], 'session_ids' => $ids], 'integration', null);
        } catch (T3ServerException) {
            // Registration remains valid when the read of older sessions fails; those sessions expire.
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'operation.target_busy') {
                throw $exception;
            }
            // An earlier cleanup is pending. These older sessions still have their original expiry.
        }
    }
}
