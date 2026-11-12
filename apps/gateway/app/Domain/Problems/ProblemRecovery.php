<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\GatewayRelease;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\TaskVmImageBuild;
use App\Models\Tool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Positive recovery evidence belongs to the incident, independent of its filing episode. */
final readonly class ProblemRecovery
{
    /** @param Collection<string, ProblemFingerprint> $rows */
    public function doctor(DoctorReportData $report, Carbon $startedAt, Collection $rows): void
    {
        // An array-cast attribute is decoded on every read. Decode each row once and group it by Node and family,
        // so a run costs one decode per row instead of one per row, family and field.
        $grouped = [];
        foreach ($rows as $row) {
            $evidence = $row->evidence;
            $node = $evidence['doctor_node'] ?? null;
            $family = $evidence['doctor_family'] ?? null;
            if (is_string($node) && is_string($family)) {
                $grouped[$node."\0".$family][] = [$row, $evidence];
            }
        }
        $started = $startedAt->toISOString();
        foreach ($report->nodes as $node) {
            foreach ($node->families as $family) {
                if ($family->observedResources === [] && $family->observedChecks === [] && $family->observedInventories === []) {
                    continue;
                }
                $candidates = $grouped[$node->nodeId."\0".$family->family->value] ?? [];
                if ($candidates === []) {
                    continue;
                }
                $observedResources = array_fill_keys(array_map(strval(...), $family->observedResources), true);
                foreach ($candidates as [$row, $evidence]) {
                    $observed = $evidence['doctor_observed_at'] ?? null;
                    $resource = $evidence['doctor_resource'] ?? null;
                    if ($resource !== null && ! is_int($resource) && ! is_string($resource)) {
                        continue;
                    }
                    $code = $evidence['doctor_code'] ?? null;
                    if (! is_string($code)) {
                        continue;
                    }
                    $partial = in_array($code, $family->observedChecks[(string) $resource] ?? [], true)
                        || (isset($family->observedInventories[$code]) && ! in_array((string) $resource, array_map(strval(...), $family->observedInventories[$code]), true));
                    $checked = $partial || isset($observedResources[(string) $resource]);
                    $failed = array_any($family->issues, static fn ($issue): bool => ProblemCollector::recordsDoctorIssue($issue)
                        && ($partial ? $issue->code === $code && (string) $issue->resourceId === (string) $resource : $issue->resourceId === null || (string) $issue->resourceId === (string) $resource));
                    if ($checked && ! $failed && is_string($observed) && $observed < $started) {
                        $this->resolve($row, 'fresh_doctor_success');
                    }
                }
            }
        }
    }

    public function reason(ProblemFingerprint $row): ?string
    {
        if ($row->source === ProblemSource::Doctor) {
            return $this->doctorResourceGone($row) ? 'resource_gone' : null;
        }
        if ($row->source !== ProblemSource::Release) {
            return null;
        }
        $resource = $row->evidence['incident_resource'] ?? null;
        $attempt = $row->evidence['incident_attempt'] ?? null;
        $kind = $row->evidence['release_kind'] ?? null;
        if (! is_string($resource) || ! is_string($attempt)) {
            return null;
        }
        if ($resource === 'gateway') {
            return $this->gateway($attempt, $kind, $row);
        }
        $imageBuildId = RecipeMaintenanceAlerts::parseImageIncident($resource);
        if ($imageBuildId !== null) {
            $build = TaskVmImageBuild::query()->find($imageBuildId);
            if (! $build instanceof TaskVmImageBuild) {
                return 'resource_gone';
            }
            $desired = DB::table('task_vm_image_recipe')->where('id', 1)->value('identity');
            if (is_string($desired) && $desired !== $build->recipe_identity) {
                return 'recipe_generation_superseded';
            }
            if (TaskVmImageBuild::query()->where('host_node_id', $build->host_node_id)->where('recipe_identity', $build->recipe_identity)
                ->where('id', '>', $build->id)->where('state', TaskVmImageBuildState::Succeeded)->whereNotNull('promoted_at')->exists()) {
                return 'verified_image_build';
            }

            return $build->state === TaskVmImageBuildState::Succeeded && $build->attempt > (int) $attempt ? 'verified_image_build' : null;
        }
        if (str_starts_with($resource, 'fleet-node:')) {
            $nodeId = (int) substr($resource, 11);
            $visits = Operation::query()->where('kind', 'fleet.rollout')->whereIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])
                ->where('finished_at', '>', $row->filed_at ?? $row->first_seen);
            foreach ($visits->lazyById(100) as $operation) {
                $nodes = $operation->result['nodes'] ?? [];
                foreach (is_array($nodes) ? $nodes : [] as $node) {
                    if (is_array($node) && ($node['node_id'] ?? null) === $nodeId && in_array($node['outcome'] ?? null, ['converged', 'unchanged'], true)) {
                        return 'verified_node_convergence';
                    }
                }
            }

            return null;
        }
        if (str_starts_with($resource, 'instance:')) {
            $instance = Instance::query()->find((int) substr($resource, 9));
            if (! $instance instanceof Instance) {
                return 'resource_gone';
            }
            if ($kind === ReleaseAlertKind::InstanceDeployPaused->value) {
                return new AutomaticDeployHistory($instance)->pausedBy() === null && $instance->auto_deploy ? 'automatic_deployment_resumed' : null;
            }
            $query = InstanceDeployment::query()->where('instance_id', $instance->id)->where('status', 'succeeded');
            if (ctype_digit($attempt)) {
                $query->where('id', '>=', (int) $attempt);
            } elseif (str_starts_with($attempt, 'pending:')) {
                $sha = substr($attempt, 8);
                $seen = $row->first_seen ?? $row->filed_at;
                $state = $instance->auto_deploy_state;
                $candidate = data_get($state, 'commit');
                $since = data_get($state, 'since');
                if (is_string($candidate) && $candidate !== $sha && is_string($since) && $seen !== null && Carbon::parse($since)->gt($seen)) {
                    return 'deployment_candidate_superseded';
                }
                $query->where('commit', $sha)->where('finished_at', '>', $seen);
            } else {
                return null;
            }

            return $query->exists() ? 'successful_deployment' : null;
        }

        return null;
    }

    public function resolve(ProblemFingerprint $row, string $reason): void
    {
        if (isset($row->evidence['resolved_at'])) {
            return;
        }
        $row->evidence = [...$row->evidence, 'resolved_at' => now()->toISOString(), 'resolution' => $reason];
        $row->save();
    }

    private function gateway(string $attempt, mixed $kind, ProblemFingerprint $row): ?string
    {
        $automation = app(GatewayReleaseAutomation::class);
        if ($kind === ReleaseAlertKind::ReleaseStalled->value) {
            $tick = $automation->lastTick();
            $seen = $row->first_seen ?? $row->filed_at;
            if ($tick !== null && $seen !== null && Carbon::parse($tick['checked_at'])->gt($seen) && in_array($tick['result'], ['released', 'up_to_date'], true)) {
                if (str_starts_with($attempt, 'stall:') && $automation->stall() === null) {
                    return 'release_runner_recovered';
                }
                $head = $automation->branchHead();
                if (str_starts_with($attempt, 'head:') && $head !== null && ($head['head'] !== substr($attempt, 5) || $head['head'] === $head['deployed'])) {
                    return 'branch_head_released_or_superseded';
                }
            }
        }
        if ($kind === ReleaseAlertKind::ReleaseStalled->value) {
            return null;
        }
        if ($kind === ReleaseAlertKind::ReleasePaused->value && ctype_digit($attempt) && $automation->resumedAfter() >= (int) $attempt && $automation->pause() === null) {
            return 'automatic_releases_resumed';
        }
        $records = GatewayRelease::query()->where('outcome', 'verified');
        if (ctype_digit($attempt)) {
            $records->where('id', $kind === ReleaseAlertKind::ReleasePaused->value ? '>' : '>=', (int) $attempt);
        } else {
            $records->where('updated_at', '>', $row->first_seen ?? $row->filed_at);
        }
        foreach ($records->orderByDesc('id')->get() as $record) {
            if ($kind === ReleaseAlertKind::ReleaseSchedulerSilent->value && data_get($record->phases, 'tick.outcome') !== 'confirmed') {
                continue;
            }
            if ($kind === ReleaseAlertKind::ReleaseGatewayAgentFailed->value && ! in_array(data_get($record->phases, 'scheduler.gateway_agent.outcome'), ['updated', 'unchanged'], true)) {
                continue;
            }
            if ($kind === ReleaseAlertKind::ReleaseCleanupPaused->value && $record->cleanup_paused) {
                continue;
            }
            if ($kind === ReleaseAlertKind::ReleasePaused->value && $automation->pause() !== null) {
                continue;
            }

            return 'verified_release';
        }

        return null;
    }

    private function doctorResourceGone(ProblemFingerprint $row): bool
    {
        $family = $row->evidence['doctor_family'] ?? null;
        $id = $row->evidence['doctor_resource'] ?? null;
        $node = $row->evidence['doctor_node'] ?? null;
        $nodeId = is_string($node) && ctype_digit($node) ? (int) $node : null;

        return ($row->evidence['doctor_resource_present'] ?? null) === true && is_string($family) && (is_int($id) || is_string($id))
            && $this->resourceExists($family, $id, $nodeId) === false;
    }

    /**
     * @param  list<array{fingerprint: string, doctor_family: string, doctor_resource: ?string, doctor_node: string}>  $issues
     * @return array<string, bool|null>
     */
    public function doctorResourcePresence(array $issues): array
    {
        $resources = [];
        $ids = [];
        foreach ($issues as $issue) {
            $resource = $this->resource($issue['doctor_family'], $issue['doctor_resource'], (int) $issue['doctor_node']);
            $resources[$issue['fingerprint']] = $resource;
            if ($resource !== null) {
                [$model, $id] = $resource;
                $ids[$model][] = $id;
            }
        }
        $present = [];
        foreach ($ids as $model => $keys) {
            foreach ($model::query()->whereIn('id', array_values(array_unique($keys)))->pluck('id') as $id) {
                if (is_int($id) || is_string($id)) {
                    $present[$model][$id] = true;
                }
            }
        }
        $result = [];
        foreach ($resources as $fingerprint => $resource) {
            $result[$fingerprint] = $resource === null ? null : isset($present[$resource[0]][$resource[1]]);
        }

        return $result;
    }

    public function resourceExists(string $family, int|string|null $id, ?int $nodeId): ?bool
    {
        $resource = $this->resource($family, $id, $nodeId);

        return $resource === null ? null : $resource[0]::query()->whereKey($resource[1])->exists();
    }

    /** @return array{class-string<Model>, int|string}|null */
    private function resource(string $family, int|string|null $id, ?int $nodeId): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (is_string($id) && str_starts_with($id, DoctorResources::NodeCheckPrefix)) {
            return $nodeId !== null && DoctorResources::isNodeCheckId($id, $nodeId) ? [Node::class, $nodeId] : null;
        }
        $model = match ($family) {
            'node' => Node::class, 'role' => NodeRole::class, 'project' => Project::class,
            'instance' => Instance::class, 'process' => Process::class, 'schedule' => Schedule::class,
            'tool' => Tool::class, 'firewall' => FirewallRule::class, 'route' => Route::class,
            default => null,
        };
        if ($family === 'database_connection') {
            $connectionId = DoctorResources::rowModelId(DoctorResourceKind::Connections, $id);
            $attachmentId = DoctorResources::rowModelId(DoctorResourceKind::Attachments, $id);

            return match (true) {
                $connectionId !== null => [DatabaseConnection::class, $connectionId],
                $attachmentId !== null => [DatabaseConnectionTarget::class, $attachmentId],
                default => null,
            };
        }

        return $model === null ? null : [$model, $id];
    }
}
