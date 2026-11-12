<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Actions\GatewayReleases\ShowGatewayReleaseAutomationAction;
use App\Data\Fleet\DesiredFleetStateData;
use App\Data\Gateway\GatewayAutoReleaseStatusData;
use App\Data\Gateway\GatewayStatusData;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayVersion;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationReconcileHealth;
use App\Domain\Problems\ProblemSource;
use App\Models\Node;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use Illuminate\Foundation\Application;
use Throwable;

/**
 * Gateway status: the version, the release the Gateway runs from, and, for an active WireGuard peer,
 * the automatic release state. An anonymous health probe reads no release state from the database.
 * Status stays available when the release state cannot be read; those fields are null then.
 */
final readonly class ShowGatewayStatusAction
{
    public function __construct(
        private DesiredFleetState $fleet,
        private ShowGatewayReleaseAutomationAction $releases,
        private GatewayReleaseAutomation $automation,
        private NodeAccessAuthorizer $access,
    ) {}

    /**
     * The desired fleet state is shown only to an active WireGuard peer, as the release endpoint does, and only
     * from the cache: a status request never waits for Git or GitHub, because release verification calls it.
     */
    public function handle(?Node $consumer): GatewayStatusData
    {
        [$release, $sha] = $this->releases->current();
        $observation = $consumer !== null ? ProblemCollectorState::query()->orderBy('id')->first()?->observation : null;
        // Status explicitly includes the caller's own Node; other commands keep the shared grant rule.
        $visibleIds = $consumer !== null ? array_fill_keys([$consumer->id, ...$this->access->accessibleNodeIds($consumer)], true) : [];
        $gatewayId = $consumer !== null ? $this->access->activeGatewayId() : null;

        return new GatewayStatusData(
            name: 'orbit-gateway',
            status: 'ok',
            version: GatewayVersion::current(),
            phpVersion: PHP_VERSION,
            laravelVersion: Application::VERSION,
            desiredFleetState: $consumer !== null ? $this->desiredFleetState() : null,
            release: $release,
            releaseSha: $sha,
            autoRelease: $consumer !== null ? $this->autoRelease() : null,
            doctor: $consumer !== null ? $this->doctor($observation, $visibleIds) : null,
            reconcile: $gatewayId !== null && isset($visibleIds[$gatewayId]) ? app(OperationReconcileHealth::class)->facts($observation) : null,
        );
    }

    /**
     * @param array<string, mixed>|null $observation
     * @param array<int, true> $visibleIds
     * @return array{collected_at: ?string, last_run_ms: int|float|null, hidden_nodes: int, issues: list<array{node_id: int, node_name: string, code: string, count: int, since: ?string, newest_since: ?string, resource: array{type: string, id: ?string}|null}>} */
    private function doctor(?array $observation, array $visibleIds): array
    {
        $startedAt = $observation['doctor_started_at'] ?? null;
        // Only what the latest full collection saw is an open issue. A row that an older collection wrote and no
        // later one checks again stays in the store as history and is not shown.
        $rows = is_string($startedAt)
            ? ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->whereNull('evidence->resolved_at')
                ->where('evidence->doctor_observed_at', '>=', $startedAt)->orderBy('id')->get()
            : ProblemFingerprint::query()->whereRaw('0 = 1')->get();
        $nodes = Node::query()->whereIn('id', $rows->map(fn (ProblemFingerprint $row): mixed => $row->evidence['doctor_node'] ?? null))->get()->keyBy('id');
        $groups = [];
        foreach ($rows as $row) {
            $nodeId = $row->evidence['doctor_node'] ?? null;
            $code = $row->evidence['doctor_code'] ?? null;
            $node = is_string($nodeId) && ctype_digit($nodeId) ? $nodes->get((int) $nodeId) : null;
            if (! $node instanceof Node || ! is_string($code)) {
                continue;
            }
            $key = $nodeId.'|'.$code;
            $since = $row->first_seen?->toISOString();
            $count = $row->evidence['doctor_issue_count'] ?? 1;
            $count = is_int($count) ? $count : 1;
            if (! isset($groups[$key])) {
                $resource = $row->evidence['doctor_resource'] ?? null;
                $groups[$key] = ['node_id' => $node->id, 'node_name' => $node->name, 'code' => $code, 'count' => $count,
                    'since' => $since, 'newest_since' => $since,
                    'resource' => ['type' => is_string($row->evidence['doctor_family'] ?? null) ? $row->evidence['doctor_family'] : '',
                        'id' => is_int($resource) || is_string($resource) ? (string) $resource : null]];
            } else {
                $group = &$groups[$key];
                $group['count'] += $count;
                $group['since'] = $group['since'] === null ? $since : ($since === null ? $group['since'] : min($group['since'], $since));
                $group['newest_since'] = max($group['newest_since'], $since);
                $group['resource'] = null;
                unset($group);
            }
        }
        $collectedAt = $observation['doctor_collected_at'] ?? null;
        $collectedAt = is_string($collectedAt) ? $collectedAt : null;
        $duration = $observation['doctor_duration_ms'] ?? null;
        $duration = is_int($duration) || is_float($duration) ? $duration : null;
        if ($duration !== null && $duration > 1000 && $collectedAt !== null) {
            $gateway = Node::query()->find($this->access->activeGatewayId());
            if ($gateway instanceof Node) {
                $groups['slow'] = ['node_id' => $gateway->id, 'node_name' => $gateway->name, 'code' => 'gateway.doctor_collection_slow',
                    'count' => 1, 'since' => $collectedAt, 'newest_since' => $collectedAt, 'resource' => null];
            }
        }
        $signals = app(OperationReconcileHealth::class)->issues($observation);
        if ($signals !== []) {
            $gateway = Node::query()->find($this->access->activeGatewayId());
            if ($gateway instanceof Node) {
                foreach ($signals as $signal) {
                    $groups[$signal['code']] = ['node_id' => $gateway->id, 'node_name' => $gateway->name, ...$signal];
                }
            }
        }
        $hidden = [];
        $issues = [];
        foreach ($groups as $group) {
            if (isset($visibleIds[$group['node_id']])) {
                $issues[] = $group;
            } else {
                $hidden[$group['node_id']] = true;
            }
        }
        usort($issues, static fn (array $left, array $right): int => strcmp($right['newest_since'] ?? '', $left['newest_since'] ?? '')
            ?: [$left['node_id'], $left['code']] <=> [$right['node_id'], $right['code']]);

        return ['collected_at' => $collectedAt, 'last_run_ms' => $duration, 'hidden_nodes' => count($hidden), 'issues' => $issues];
    }

    /** A release that has no agent manifest has no desired fleet state; status still answers. */
    private function desiredFleetState(): ?DesiredFleetStateData
    {
        try {
            return $this->fleet->cached();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function autoRelease(): ?GatewayAutoReleaseStatusData
    {
        try {
            $tick = $this->automation->lastTick();

            return new GatewayAutoReleaseStatusData(
                enabled: $this->automation->enabled(),
                paused: $this->automation->pause() !== null,
                lastCheckedAt: $tick['checked_at'] ?? null,
                lastResult: $tick['result'] ?? null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
