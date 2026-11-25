<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\CurrentRecipe;
use App\Infrastructure\MachineSoftware\FolderPin;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\ConnServerNode;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;

/**
 * Admits the rollout of the pinned Conn Server to the Nodes that run one (docs/reference/development-containers.md#conn-server-rollout):
 * the agent folder's `conn-server` pin, its macOS arm64 app, each Node's record with its SSH target in visiting order, and the notice target.
 * A dry run only downloads and checks. Scope `canary` reaches the canary alone; `fleet` reaches the canary, then the rest; `nodes`
 * reaches the named Nodes alone, in the caller's order. Release convergence checks pins that request a canary.
 */
final readonly class ConnServerRollout
{
    public const string Kind = 'fleet.conn_server_rollout';

    /** The scopes a caller must choose from; there is no default that reaches the whole fleet. */
    public const array Scopes = ['canary', 'fleet', 'nodes'];

    public function __construct(private StartOperationAction $start, private CurrentRecipe $recipes, private SwampSshExecutionInput $ssh) {}

    private function fingerprint(FolderPin $pin, ConnServerNode $canary): string
    {
        return hash('sha256', json_encode([$pin->version, $pin->artifact, $pin->macApp, $canary->node_id, $canary->platform,
            $canary->path, $canary->port, $canary->service, $canary->user], JSON_THROW_ON_ERROR));
    }

    private function latestProbe(): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->whereNotNull('input->canary_pin')->latest('id')->first();
    }

    private function verified(?Operation $probe, string $key): bool
    {
        return $probe?->status === OperationStatus::Succeeded && $probe->input['canary_pin'] === $key
            && data_get($probe->input, 'intent.dry_run') === false && data_get($probe->result, 'nodes.0.thread_read') === true
            && data_get($probe->result, 'nodes.0.outcome') === 'updated'
            && data_get($probe->result, 'nodes.0.node_id') === data_get($probe->input, 'intent.nodes.0.node_id');
    }

    /** Start or observe the dry run and client check for the serving pin. The release lock serializes callers. */
    public function releaseGate(): ?Operation
    {
        $pin = $this->recipes->currentRecipe()->instances?->agent['conn-server'] ?? null;
        if (! $pin?->releaseCanary) {
            return null;
        }
        $servers = ConnServerNode::query()->where('canary', true)->get();
        if ($servers->count() !== 1) {
            throw new ResourceOperationException('conn_server.canary_unset', 'Exactly one Conn Server Node must be the canary.', 409);
        }
        $canary = $servers->sole();
        $key = $this->fingerprint($pin, $canary);
        $probe = $this->latestProbe();
        if ($probe === null || $probe->input['canary_pin'] !== $key) {
            return $this->admit(true, 'canary', triggeredBy: 'gateway-release');
        }
        if ($this->verified($probe, $key)) {
            return null;
        }
        if ($probe->status === OperationStatus::Succeeded && data_get($probe->input, 'intent.dry_run') === true
            && data_get($probe->result, 'nodes.0.outcome') === 'checked') {
            return $this->admit(false, 'canary', triggeredBy: 'gateway-release');
        }

        return $probe;
    }

    /** @param list<int>|null $nodeIds the Nodes of scope `nodes`, in visiting order; each has a record (AdmitConnServerRolloutRequest) */
    public function admit(bool $dryRun, string $scope, ?array $nodeIds = null, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        $pin = $this->recipes->currentRecipe()->instances?->agent['conn-server'] ?? null;
        if ($pin?->macApp === null) {
            throw new ResourceOperationException('conn_server.pin_unavailable', 'The Conn Server pin names no macOS arm64 app.', 409);
        }
        $servers = ConnServerNode::query()->with('node')->orderByDesc('canary')->orderBy('node_id')->get();
        if ($servers->where('canary', true)->count() !== 1) {
            throw new ResourceOperationException('conn_server.canary_unset', 'Exactly one Conn Server Node must be the canary.', 409);
        }
        $canary = $servers->where('canary', true)->sole();
        $proof = $pin->releaseCanary ? $this->fingerprint($pin, $canary) : null;
        if ($proof !== null && ! $dryRun && $scope !== 'canary' && ! $this->verified($this->latestProbe(), $proof)) {
            throw new ResourceOperationException('conn_server.canary_required', 'Verify this pin on the canary before promotion.', 409);
        }
        if ($scope === 'canary') {
            $servers = $servers->where('canary', true);
        } elseif ($scope === 'nodes') {
            $servers = collect($nodeIds)->map(fn (int $id): ConnServerNode => $servers->firstOrFail('node_id', $id));
        }
        $notice = Config::array('orbit.conn.server_rollout_notice');

        return $this->start->execute(self::Kind, 'fleet', SwampNode::id(), SwampNode::id(), [...($proof !== null && $scope === 'canary' ? ['canary_pin' => $proof] : []), 'intent' => [
            ...($proof !== null && $scope === 'canary' ? ['probe_thread' => true] : []),
            'version' => $pin->version,
            'dry_run' => $dryRun,
            'mac' => ['url' => $pin->macApp->artifact->location, 'sha256' => $pin->macApp->artifact->digest,
                'bundle_id' => $pin->macApp->bundleId, 'team_id' => $pin->macApp->teamId],
            'linux' => ['url' => $pin->artifact->location, 'sha256' => $pin->artifact->digest],
            'notice' => ['url' => $notice['url'] ?? null, 'command' => $notice['command'] ?? null],
            'nodes' => $servers->map(fn (ConnServerNode $server): array => ['node_id' => $server->node_id, 'ssh' => $this->ssh->forNode($server->node),
                'server' => ['platform' => $server->platform, 'path' => $server->path, 'service' => $server->service, 'port' => $server->port,
                    'user' => $server->user, 'canary' => $server->canary, 'notify_before_restart' => $server->notify_before_restart]])->values()->all(),
        ]], $triggeredBy, $requestId, lockKey: $proof !== null ? 'fleet:conn-server-rollout' : null);
    }
}
