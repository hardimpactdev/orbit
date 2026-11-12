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
    ) {}

    /**
     * The desired fleet state is shown only to an active WireGuard peer, as the release endpoint does, and only
     * from the cache: a status request never waits for Git or GitHub, because release verification calls it.
     */
    public function handle(bool $authenticated): GatewayStatusData
    {
        [$release, $sha] = $this->releases->current();

        return new GatewayStatusData(
            name: 'orbit-gateway',
            status: 'ok',
            version: GatewayVersion::current(),
            phpVersion: PHP_VERSION,
            laravelVersion: Application::VERSION,
            desiredFleetState: $authenticated ? $this->desiredFleetState() : null,
            release: $release,
            releaseSha: $sha,
            autoRelease: $authenticated ? $this->autoRelease() : null,
            doctor: $authenticated ? $this->doctor() : null,
        );
    }

    /** @return array{collected_at: ?string, issues: list<array{node_id: int, node_name: string, code: string, count: int, since: ?string, newest_since: ?string, resource: array{type: string, id: ?string}|null}>} */
    private function doctor(): array
    {
        $rows = ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->whereNull('evidence->resolved_at')->orderBy('id')->get();
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
            if (! isset($groups[$key])) {
                $resource = $row->evidence['doctor_resource'] ?? null;
                $groups[$key] = ['node_id' => $node->id, 'node_name' => $node->name, 'code' => $code, 'count' => 1,
                    'since' => $since, 'newest_since' => $since,
                    'resource' => ['type' => is_string($row->evidence['doctor_family'] ?? null) ? $row->evidence['doctor_family'] : '',
                        'id' => is_int($resource) || is_string($resource) ? (string) $resource : null]];
            } else {
                $group = &$groups[$key];
                $group['count']++;
                $group['since'] = $group['since'] === null ? $since : ($since === null ? $group['since'] : min($group['since'], $since));
                $group['newest_since'] = max($group['newest_since'], $since);
                $group['resource'] = null;
                unset($group);
            }
        }
        $issues = array_values($groups);
        usort($issues, static fn (array $left, array $right): int => strcmp($right['newest_since'] ?? '', $left['newest_since'] ?? '')
            ?: [$left['node_id'], $left['code']] <=> [$right['node_id'], $right['code']]);
        $observation = ProblemCollectorState::query()->orderBy('id')->first()?->observation;
        $collectedAt = $observation['doctor_collected_at'] ?? null;

        return ['collected_at' => is_string($collectedAt) ? $collectedAt : null, 'issues' => $issues];
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
