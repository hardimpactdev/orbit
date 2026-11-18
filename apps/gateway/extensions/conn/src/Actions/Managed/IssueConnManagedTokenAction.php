<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions\Managed;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedSecrets;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedTargets;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;
use Orbit\Extensions\Conn\Models\ConnManagedToken;

/**
 * Issues one reader token to a caller with a direct edge to the target's serving Node ([Caller route](/reference/conn#caller-route)).
 * It reads the edge and the revision in an IMMEDIATE transaction, so no token is issued against an edge already removed.
 */
final readonly class IssueConnManagedTokenAction
{
    /** @var list<string> */
    public const array ReaderScopes = ['orchestration:read', 'terminal:read'];

    public const int LifetimeMinutes = 15;

    /** @return array{token: string, expires_at: string, scopes: list<string>, generation: int, revision: int} */
    public function execute(Node $caller, string $environmentId): array
    {
        ConnManagedTargets::requireEnabled();

        return DB::transaction(function () use ($caller, $environmentId): array {
            $target = ConnManagedTarget::query()->where('environment_id', $environmentId)->first();
            if (! $target instanceof ConnManagedTarget) {
                throw new ResourceOperationException('conn.managed_target_unknown', 'The environment has no managed target.', 404);
            }
            $edge = NodeAccess::query()->where('consumer_node_id', $caller->id)->where('serving_node_id', $target->node_id)->first();
            if (! $edge instanceof NodeAccess) {
                throw new ResourceOperationException('conn.managed_direct_edge_required', 'The caller has no direct edge to the serving Node.', 403);
            }

            $token = ConnManagedSecrets::token();
            $expiresAt = now()->addMinutes(self::LifetimeMinutes);
            ConnManagedToken::query()->create([
                'conn_managed_target_id' => $target->id,
                'generation' => $target->generation,
                'caller_node_id' => $caller->id,
                'node_access_id' => $edge->id,
                'token_digest' => ConnManagedSecrets::digest($token),
                'scopes' => self::ReaderScopes,
                'expires_at' => $expiresAt,
            ]);

            return [
                'token' => $token,
                'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s.up'),
                'scopes' => self::ReaderScopes,
                'generation' => $target->generation,
                'revision' => $target->revision,
            ];
        });
    }
}
