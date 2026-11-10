<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Carbon;

/**
 * Whether a Node of the rollout set runs behind the desired fleet state: the rollout plan's selection and
 * Doctor's `node.release_lag` (ADR 0202). The rollout keeps no records of its own, so a Node's last visit is
 * its newest result in the terminal `fleet.rollout` Operations of the commit. It reads only the Gateway's
 * records and the agent view, never the Node.
 */
final readonly class FleetReleaseLag
{
    /** A Node waits this long after its last visit ended before a pass visits it again. */
    public const int RetryAfterSeconds = 300;

    public function __construct(
        private DesiredFleetState $desired,
        private FleetRolloutMembership $membership,
        private AgentReportedVersions $versions,
        private AgentDelivery $delivery,
        private bool $enabled,
    ) {}

    /**
     * The last visit of each of the Nodes for the commit, keyed by Node id, from Operations before `$before` when
     * given. A `pending` entry names a Node that a run admitted but did not reach, so an earlier visit stands. The
     * read stops once every Node has its newest visit.
     *
     * @param  list<int>  $nodeIds
     * @return array<int, array{outcome: string, error_code: ?string, cli_version: ?string, finished_at: Carbon, operation_id: int}>
     */
    public function visits(string $commit, array $nodeIds, ?int $before = null): array
    {
        $wanted = array_fill_keys($nodeIds, true);
        $visits = [];
        $operations = Operation::query()
            ->where('kind', 'fleet.rollout')
            ->where('input->commit', $commit)
            ->whereIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])
            ->whereNotNull('finished_at')
            ->when($before !== null, static fn ($query) => $query->where('id', '<', $before));

        foreach ($operations->lazyByIdDesc(100) as $operation) {
            $nodes = $operation->result['nodes'] ?? [];

            foreach (is_array($nodes) ? $nodes : [] as $node) {
                $id = is_array($node) ? ($node['node_id'] ?? null) : null;
                $outcome = is_array($node) ? ($node['outcome'] ?? null) : null;

                if (! is_int($id) || ! isset($wanted[$id]) || ! is_string($outcome) || $outcome === 'pending' || isset($visits[$id]) || ! $operation->finished_at instanceof Carbon) {
                    continue;
                }

                $visits[$id] = [
                    'outcome' => $outcome,
                    'error_code' => is_string($node['error_code'] ?? null) ? $node['error_code'] : null,
                    'cli_version' => is_string($node['cli_version'] ?? null) ? $node['cli_version'] : null,
                    'finished_at' => $operation->finished_at,
                    'operation_id' => $operation->id,
                ];
            }

            if (count($visits) === count($wanted)) {
                break;
            }
        }

        return $visits;
    }

    /**
     * Why the Node runs behind the state, or null when its last visit converged it and it did not drift since:
     * its observer reports another version, or its recorded CLI version differs from an available CLI release.
     *
     * @param  array{outcome: string, cli_version: ?string}|null  $visit
     */
    public function behind(Node $node, ?array $visit, DesiredFleetStateData $state): ?string
    {
        if ($visit === null) {
            return 'no visit yet';
        }

        if (! in_array($visit['outcome'], ['converged', 'unchanged'], true)) {
            return $visit['outcome'];
        }

        $reported = $this->versions->get($node->id)['version'] ?? null;

        return ($reported !== null && $reported !== $this->delivery->reportedVersion($node))
            || (CliConvergePlan::required($node) && ($visit['cli_version'] === null
                || ($state->cli->isAvailable() && $visit['cli_version'] !== $state->cli->version)))
            ? 'drifted' : null;
    }

    /**
     * Why the Node lags, or null when it runs the desired state or the check does not apply: the rollout
     * is off, the Node is outside the rollout set, the desired state is not resolved yet, or the Gateway's
     * commit is unknown.
     *
     * @return array{expected: string, observed: string}|null
     */
    public function observe(Node $node): ?array
    {
        if (! $this->enabled || ! $this->membership->includes($node)) {
            return null;
        }

        // Doctor never waits on Git or GitHub: the rollout plan keeps the desired state cached.
        $state = $this->desired->cached();

        if ($state === null || $state->commit === null) {
            return null;
        }

        $observed = $this->behind($node, $this->visits($state->commit, [$node->id])[$node->id] ?? null, $state);

        return $observed === null ? null : ['expected' => substr($state->commit, 0, 12), 'observed' => $observed];
    }
}
