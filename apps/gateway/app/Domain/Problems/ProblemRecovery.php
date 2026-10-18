<?php

declare(strict_types=1);

namespace App\Domain\Problems;

use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use App\Models\TaskVmImageBuild;
use App\Models\Tool;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Positive recovery evidence belongs to the incident, independent of its filing episode. */
final readonly class ProblemRecovery
{
    public function doctor(DoctorReportData $report, Carbon $startedAt): void
    {
        foreach ($report->nodes as $node) {
            foreach ($node->families as $family) {
                if ($family->observedResources === [] && $family->observedChecks === [] && $family->observedInventories === []) {
                    continue;
                }
                $rows = ProblemFingerprint::query()->where('source', ProblemSource::Doctor)
                    ->where('evidence->doctor_node', (string) $node->nodeId)
                    ->where('evidence->doctor_family', $family->family->value)->lockForUpdate()->get();
                foreach ($rows as $row) {
                    $observed = $row->evidence['doctor_observed_at'] ?? null;
                    $resource = $row->evidence['doctor_resource'] ?? null;
                    if ($resource !== null && ! is_int($resource) && ! is_string($resource)) {
                        continue;
                    }
                    $code = explode('|', $row->fingerprint)[1] ?? '';
                    $partial = in_array($code, $family->observedChecks[(string) $resource] ?? [], true)
                        || (isset($family->observedInventories[$code]) && ! in_array((string) $resource, array_map(strval(...), $family->observedInventories[$code]), true));
                    $checked = $partial || in_array((string) $resource, array_map(strval(...), $family->observedResources), true);
                    $failed = array_any($family->issues, static fn ($issue): bool => $issue->kind !== DoctorIssueKind::Informational
                        && ($partial ? $issue->code === $code && (string) $issue->resourceId === (string) $resource : $issue->resourceId === null || (string) $issue->resourceId === (string) $resource));
                    if ($checked && ! $failed && is_string($observed) && $observed < $startedAt->toISOString()) {
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
        if (str_starts_with($resource, 'upcloud:')) {
            $build = UpCloudTaskVmImageBuild::query()->where('recipe_identity', substr($resource, 8))
                ->where('id', '>=', (int) $attempt)->where('state', TaskVmImageBuildState::Succeeded)->whereNotNull('template_uuid')->first();

            if ($build instanceof UpCloudTaskVmImageBuild) {
                return 'verified_template';
            }
            $desired = DB::table('task_vm_image_recipe')->where('id', 1)->value('identity');

            return is_string($desired) && $desired !== substr($resource, 8) && UpCloudTaskVmImageBuild::query()
                ->where('id', '>', (int) $attempt)->where('recipe_identity', $desired)->where('state', TaskVmImageBuildState::Succeeded)->whereNotNull('template_uuid')->exists()
                ? 'verified_recipe_supersession' : null;
        }
        if (str_starts_with($resource, 'recipe-fleet:')) {
            $parts = explode(':', $attempt);
            $generation = (int) $parts[0];
            $desired = DB::table('task_vm_image_recipe')->where('id', 1)->first();
            if ($desired !== null && (int) $desired->generation > $generation) {
                return 'recipe_generation_superseded';
            }
            $build = TaskVmImageBuild::query()->find((int) ($parts[1] ?? 0));

            return $build instanceof TaskVmImageBuild && $build->recipe_identity === substr($resource, 13)
                && ($build->fleet_convergence['generation'] ?? null) === $generation && ($build->fleet_convergence['status'] ?? null) === 'succeeded'
                ? 'verified_recipe_convergence' : null;
        }
        if (str_starts_with($resource, 'fleet')) {
            $rollout = FleetRollout::query()->find((int) $attempt);

            if (! $rollout instanceof FleetRollout) {
                return null;
            }
            if ($rollout->status === FleetRolloutStatus::Superseded) {
                return 'rollout_superseded';
            }
            if ($resource === 'fleet' && FleetRollout::query()->where('id', '>', $rollout->id)->where('status', FleetRolloutStatus::Completed)
                ->where('desired_state->cli->status', 'available')->whereNull('desired_state->cli->reason')->exists()) {
                return 'verified_rollout_supersession';
            }
            if ($resource === 'fleet' && $kind === ReleaseAlertKind::RolloutStalled->value
                && $rollout->status !== FleetRolloutStatus::Waiting && data_get($rollout->desired_state, 'cli.status') === 'available') {
                return 'cli_release_available';
            }
            if ($kind === ReleaseAlertKind::RolloutCaddySkipped->value && str_starts_with($resource, 'fleet-node:')) {
                $visits = FleetRolloutNode::query()->where('node_id', (int) substr($resource, 11))
                    ->where('fleet_rollout_id', '>=', $rollout->id)->where('finished_at', '>', $row->filed_at ?? $row->first_seen)->get();
                foreach ($visits as $visit) {
                    if ($visit->outcome->isConverged() && in_array(data_get($visit->evidence, 'footprint.artifacts.caddy'), ['applied', 'unchanged'], true)
                        && data_get($visit->evidence, 'footprint.skipped.caddy') === null) {
                        return 'verified_caddy_publication';
                    }
                }

                return null;
            }
            if (str_starts_with($resource, 'fleet-node:') && $kind !== ReleaseAlertKind::RolloutCaddySkipped->value) {
                $visits = FleetRolloutNode::query()->where('node_id', (int) substr($resource, 11))->where('fleet_rollout_id', '>=', $rollout->id)->get();

                return $visits->contains(static fn (FleetRolloutNode $visit): bool => $visit->outcome->isConverged()) ? 'verified_node_convergence' : null;
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

        return ($row->evidence['doctor_resource_present'] ?? null) === true && is_string($family) && (is_int($id) || is_string($id))
            && $this->resourceExists($family, $id) === false;
    }

    public function resourceExists(string $family, int|string|null $id): ?bool
    {
        if ($id === null || $id === '') {
            return null;
        }
        $model = match ($family) {
            'node' => Node::class, 'role' => NodeRole::class, 'project' => Project::class,
            'instance' => Instance::class, 'process' => Process::class, 'schedule' => Schedule::class,
            'tool' => Tool::class, 'firewall' => FirewallRule::class, 'route' => Route::class,
            default => null,
        };
        if ($family === 'database_connection') {
            return DatabaseConnection::query()->whereKey($id)->exists() || DatabaseConnectionTarget::query()->whereKey($id)->exists();
        }

        return $model === null ? null : $model::query()->whereKey($id)->exists();
    }
}
