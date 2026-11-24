<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\CurrentRecipe;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\ConnServerNode;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;

/**
 * Admits the rollout of the pinned Conn Server to the Nodes that run one (docs/reference/development-containers.md#conn-server-rollout):
 * the agent folder's `conn-server` pin, its macOS arm64 app, each Node's record with its SSH target, canary first, and the notice target.
 * A dry run only downloads and checks. Nothing starts it from a pin change.
 */
final readonly class ConnServerRollout
{
    public const string Kind = 'fleet.conn_server_rollout';

    public function __construct(private StartOperationAction $start, private CurrentRecipe $recipes, private SwampSshExecutionInput $ssh) {}

    public function admit(bool $dryRun, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        $pin = $this->recipes->currentRecipe()->instances?->agent['conn-server'] ?? null;
        if ($pin?->macApp === null) {
            throw new ResourceOperationException('conn_server.pin_unavailable', 'The Conn Server pin names no macOS arm64 app.', 409);
        }
        $servers = ConnServerNode::query()->with('node')->orderByDesc('canary')->orderBy('node_id')->get();
        if ($servers->where('canary', true)->count() !== 1) {
            throw new ResourceOperationException('conn_server.canary_unset', 'Exactly one Conn Server Node must be the canary.', 409);
        }
        $notice = Config::array('orbit.conn.server_rollout_notice');

        return $this->start->execute(self::Kind, 'fleet', SwampNode::id(), SwampNode::id(), ['intent' => [
            'version' => $pin->version,
            'dry_run' => $dryRun,
            'mac' => ['url' => $pin->macApp->artifact->location, 'sha256' => $pin->macApp->artifact->digest,
                'bundle_id' => $pin->macApp->bundleId, 'team_id' => $pin->macApp->teamId],
            'linux' => ['url' => $pin->artifact->location, 'sha256' => $pin->artifact->digest],
            'notice' => ['url' => $notice['url'] ?? null, 'command' => $notice['command'] ?? null],
            'nodes' => $servers->map(fn (ConnServerNode $server): array => ['node_id' => $server->node_id, 'ssh' => $this->ssh->forNode($server->node),
                'server' => ['platform' => $server->platform, 'path' => $server->path, 'service' => $server->service, 'port' => $server->port,
                    'user' => $server->user, 'canary' => $server->canary, 'notify_before_restart' => $server->notify_before_restart]])->values()->all(),
        ]], $triggeredBy, $requestId);
    }
}
