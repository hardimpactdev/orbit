<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolDefinitionResolver;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolPolicy;
use App\Infrastructure\MachineSoftware\ConnServerReleases;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\ConnServerNode;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Admits the rollout of the pinned Conn Server to the Nodes that run one (docs/reference/development-containers.md#conn-server-rollout):
 * the host release's artifacts, each Node's record with its SSH target in visiting order, and the notice target.
 * A dry run only downloads and checks. Scope `canary` reaches the canary alone; `fleet` reaches the canary, then the rest; `nodes`
 * reaches the named Nodes alone, in the caller's order. Nothing starts it from a pin change.
 */
final readonly class ConnServerRollout
{
    public const string Kind = 'fleet.conn_server_rollout';

    /** The scopes a caller must choose from; there is no default that reaches the whole fleet. */
    public const array Scopes = ['canary', 'fleet', 'nodes'];

    public function __construct(private StartOperationAction $start, private ConnServerReleases $releases, private SwampSshExecutionInput $ssh,
        private OperationIdempotency $idempotency, private ToolConvergeIntent $tools, private ToolPolicy $policy,
        private ToolDefinitionResolver $definitions, private ConnServerToolManager $manager) {}

    /** @param list<int>|null $nodeIds the Nodes of scope `nodes`, in visiting order; each has a record (AdmitConnServerRolloutRequest) */
    public function admit(bool $dryRun, string $scope, ?array $nodeIds = null, ?string $triggeredBy = null, ?string $requestId = null, ?string $version = null, bool $withdraw = false): Operation
    {
        if (($existing = $this->idempotency->replay(self::Kind)) !== null) {
            return $existing;
        }

        return DB::transaction(fn (): Operation => $this->admitLocked($dryRun, $scope, $nodeIds, $triggeredBy, $requestId, $version, $withdraw));
    }

    /** @param list<int>|null $nodeIds */
    private function admitLocked(bool $dryRun, string $scope, ?array $nodeIds, ?string $triggeredBy, ?string $requestId, ?string $version, bool $withdraw): Operation
    {
        $ids = ConnServerNode::query()->pluck('node_id')->all();
        Node::query()->whereIn('id', [SwampNode::id(), ...$ids])->orderBy('id')->lockForUpdate()->get(['id']);
        $configured = ConnServerNode::query()->with('node')->whereIn('node_id', $ids)->orderByDesc('canary')->orderBy('node_id')->get();
        $servers = $configured->where('tool_withdrawn', $withdraw);
        if ($withdraw) {
            if ($scope !== 'nodes' || count($nodeIds ?? []) !== 1 || $version !== null) {
                throw new ResourceOperationException('conn_server.withdraw_scope_invalid', 'Withdrawal requires one explicit Node and no release override.', 422);
            }
            $target = $servers->firstWhere('node_id', $nodeIds[0]);
            if ($target === null || $target->platform !== 'linux' || ! $this->manager->supportsNode($target->node)) {
                throw new ResourceOperationException('conn_server.withdraw_unsupported', 'Service withdrawal requires a configured Linux x64 Node with a withdrawn Tool membership.', 409);
            }
        } elseif (($scope === 'nodes' ? $configured : $servers)->where('canary', true)->count() !== 1) {
            throw new ResourceOperationException('conn_server.canary_unset', 'Exactly one Conn Server Node must be the canary.', 409);
        }
        if ($scope === 'canary') {
            $servers = $servers->where('canary', true);
        } elseif ($scope === 'nodes') {
            $servers = collect($nodeIds)->map(fn (int $id): ConnServerNode => $servers->firstWhere('node_id', $id)
                ?? throw new ResourceOperationException('conn_server.membership_withdrawn', 'A selected Node is not enrolled for Conn Server rollout.', 409));
        }
        $release = $this->releases->find($version);
        if ($release === null) {
            throw new ResourceOperationException('conn_server.pin_unavailable', 'The requested Conn Server host release is not in the release catalog.', 409);
        }
        $members = Tool::query()->with('manager', 'aptRepository')->whereIn('node_id', $servers->pluck('node_id'))
            ->where('package', ConnServerToolManager::Package)->whereHas('manager', static fn ($query) => $query->where('name', ToolManagerName::ConnServer->value))
            ->get()->keyBy('node_id');
        $selectedVersion = null;
        foreach ($servers as $server) {
            $this->tools->assertIdle($server->node_id, [ToolManagerName::ConnServer->value]);
            $member = $members->get($server->node_id);
            $desired = $member === null ? $release->version : $this->definitions->resolve('update', $this->manager, $member->package, $member,
                null, null, null, $this->policy->active())->version;
            if (($version !== null && $version !== $desired) || ($selectedVersion !== null && $selectedVersion !== $desired)) {
                throw new ResourceOperationException('conn_server.release_conflict', 'Selected Nodes must desire the same release. Use an explicit Node scope, or update the Tool memberships.', 409);
            }
            $selectedVersion = $desired;
        }
        $pin = $this->releases->find($selectedVersion) ?? $release;
        $notice = Config::array('orbit.conn.server_rollout_notice');

        return $this->start->execute(self::Kind, 'fleet', SwampNode::id(), SwampNode::id(), ['intent' => [
            'version' => $pin->version,
            'dry_run' => $dryRun,
            ...($withdraw ? ['withdraw' => true] : []),
            'mac' => ['url' => $pin->mac->artifact->location, 'sha256' => $pin->mac->artifact->digest,
                'bundle_id' => $pin->mac->bundleId, 'team_id' => $pin->mac->teamId],
            'linux' => ['url' => $pin->linux->location, 'sha256' => $pin->linux->digest],
            'notice' => ['url' => $notice['url'] ?? null, 'command' => $notice['command'] ?? null],
            'nodes' => $servers->map(fn (ConnServerNode $server): array => ['node_id' => $server->node_id, 'ssh' => $this->ssh->forNode($server->node),
                ...($members->has($server->node_id) ? ['tool_id' => $members->get($server->node_id)?->id] : []),
                'server' => ['platform' => $server->platform, 'path' => $server->path, 'service' => $server->service, 'port' => $server->port,
                    'user' => $server->user, 'canary' => $server->canary, 'notify_before_restart' => $server->notify_before_restart]])->values()->all(),
        ]], $triggeredBy, $requestId, lockKey: self::Kind);
    }
}
