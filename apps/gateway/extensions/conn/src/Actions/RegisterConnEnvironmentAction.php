<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Data\RegisterConnEnvironmentData;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Registers a T3 server. The Gateway checks that the URL serves the named environment and that T3 accepts
 * the admin session the server's host issued, then stores that session encrypted. Registering again
 * replaces the session and records a revision for Swamp to clean earlier Gateway sessions.
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

        $now = Carbon::now();
        $environment = DB::transaction(function () use ($node, $data, $descriptor, $now): ConnEnvironment {
            $environment = ConnEnvironment::query()->where('environment_id', $data->environmentId)->lockForUpdate()->first()
                ?? new ConnEnvironment(['environment_id' => $data->environmentId]);
            $session = $this->server->session($data->url, $data->adminSession);
            $missing = array_values(array_diff(self::REQUIRED_SCOPES, $session->scopes));

            if ($missing !== []) {
                throw new ResourceOperationException(
                    'conn.admin_scope_missing',
                    'The session is not an admin session. It lacks '.implode(', ', $missing).'.',
                    details: ['missing_scopes' => implode(' ', $missing)],
                );
            }

            $environment->fill(['label' => $data->label, 'url' => $data->url, 'node_id' => $node->id,
                'server_version' => $descriptor->serverVersion, 'admin_session' => $session->token,
                'admin_session_expires_at' => $session->expiresAt, 'registered_at' => $now,
                'cleanup_version' => $environment->cleanup_version + 1])->save();

            return $environment;
        });

        return $environment;
    }
}
