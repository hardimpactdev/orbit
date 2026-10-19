<?php

declare(strict_types=1);

use App\Domain\Doctor\InstanceDoctorIssueCode;
use App\Domain\Doctor\NodeDoctorIssueCode;
use App\Domain\Doctor\ProcessDoctorIssueCode;
use App\Domain\Doctor\ProjectDoctorIssueCode;
use App\Domain\Doctor\RoleDoctorIssueCode;
use App\Domain\Problems\ProblemEvidence;
use App\Domain\Problems\ProblemGroupSnapshot;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Problems\ProblemSuppression;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseIncident;
use App\Models\Activity;
use App\Models\FirewallRule;
use App\Models\FleetRollout;
use App\Models\GatewayRelease;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskVmImageBuild;
use App\Models\Tool;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Convert retained alert evidence once. Ambiguous history remains operator-owned. */
return new class extends Migration
{
    // Before #1356 both briefs were the symptom. #1356 kept these titles and
    // deliverables but replaced the briefs with fixed instructions. Freeze both
    // historical forms here; future filer templates must not change recognition.
    private const array HistoricalSymptomSubtasks = [
        ['Document the owning page', 'docs', 'The owning page matches the fix, or no page changes.'],
        ['Reproduce the failure and fix it', 'test', 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.'],
    ];

    private const array HistoricalInstructionBriefs = [
        'Update the page that owns the failing behaviour so it describes the fixed behaviour, or confirm that no page changes.',
        'Add a test that fails on the start commit for the symptom in the group brief. Then fix the root cause so it passes.',
    ];

    public function up(): void
    {
        ProblemFingerprint::query()->whereIn('source', [ProblemSource::Release, ProblemSource::Doctor])->orderBy('id')->eachById(function (ProblemFingerprint $row): void {
            DB::transaction(function () use ($row): void {
                $row = ProblemFingerprint::query()->lockForUpdate()->find($row->id);
                if (! $row instanceof ProblemFingerprint) {
                    return;
                }
                $group = $row->task_group_id === null ? null : Task::topLevel()->lockForUpdate()->find($row->task_group_id);
                if ($group instanceof Task && $this->generated($row, $group)) {
                    $row->evidence = [...$row->evidence, 'generated_group_hash' => app(ProblemGroupSnapshot::class)->hash($group)];
                }
                if ($row->source === ProblemSource::Doctor) {
                    $parts = explode('|', $row->fingerprint);
                    if ($this->hashedDoctor($row)) {
                        app(ProblemRecovery::class)->resolve($row, 'doctor_identity_replaced');
                    }
                    if (count($parts) === 4) {
                        $row->evidence = [...$row->evidence, 'doctor_code' => $parts[1]];
                        if ($parts[3] === 'none' || ($parts[2] === 'database_connection' && ctype_digit($parts[3]))) {
                            app(ProblemRecovery::class)->resolve($row, 'doctor_identity_replaced');
                        }
                    }
                    if (count($parts) === 4 && $parts[3] !== 'none') {
                        $row->evidence = [...$row->evidence, 'doctor_family' => $parts[2], 'doctor_resource' => $parts[3], 'doctor_resource_present' => $this->persistedDoctorResource($parts[2], $parts[1], $parts[3])];
                    }
                    $this->doctorObservation($row, $parts);
                    $row->save();

                    return;
                }
                $parts = explode('|', $row->fingerprint);
                if (count($parts) !== 4 || preg_match('/\A[0-9a-f]{40}\z/', $parts[3]) !== 1) {
                    $row->save();

                    return;
                }
                $kind = ReleaseAlertKind::tryFrom($parts[1]);
                $alerts = Activity::query()->where('command', 'release:alert')->where('properties->problem->fingerprint', $row->fingerprint)->orderBy('id')->get();
                $identities = [];
                foreach ($alerts as $alert) {
                    $identity = $kind === null ? null : $this->identity($kind, $parts[2], $alert);
                    if ($identity === null) {
                        $row->save();

                        return;
                    }
                    $identities[$identity->key($kind)] = $identity;
                }
                if ($kind === null || count($identities) !== 1) {
                    $row->save();

                    return;
                }
                $identity = array_values($identities)[0];
                $key = 'release|'.$identity->key($kind);
                $row->evidence = app(ProblemEvidence::class)->apply($row->evidence, [
                    'release_kind' => $kind->value, 'release_target' => $parts[2], 'release_shas' => [$parts[3]],
                    'incident_resource' => $identity->resource, 'incident_attempt' => $identity->attempt, 'incident_fault' => $identity->fault,
                ]);
                $canonical = ProblemFingerprint::query()->where('fingerprint', $key)->lockForUpdate()->first();
                if (! $canonical instanceof ProblemFingerprint) {
                    $row->fingerprint = $key;
                    $row->save();

                    return;
                }
                $this->merge($canonical, $row, $group);
            });
        });
    }

    public function down(): void
    {
        // The old SHA buckets combined unrelated attempts; they cannot be reconstructed.
    }

    private function persistedDoctorResource(string $family, string $code, string $id): bool
    {
        $codes = match ($family) {
            'instance' => InstanceDoctorIssueCode::class,
            'node' => NodeDoctorIssueCode::class,
            'role' => RoleDoctorIssueCode::class,
            'project' => ProjectDoctorIssueCode::class,
            'process' => ProcessDoctorIssueCode::class,
            default => null,
        };

        return ctype_digit($id) && $codes !== null && $codes::tryFrom($code) !== null && ! str_ends_with($code, '.node_unreachable');
    }

    /** @param list<string> $parts */
    private function doctorObservation(ProblemFingerprint $row, array $parts): void
    {
        if (count($parts) !== 4 || ! ctype_digit($parts[3])) {
            return;
        }
        $model = match ($parts[2]) {
            'node' => Node::class, 'instance' => Instance::class,
            'process' => Process::class, 'role' => NodeRole::class,
            'tool' => Tool::class, 'schedule' => Schedule::class,
            'firewall' => FirewallRule::class, default => null,
        };
        $resource = $model === null ? null : $model::query()->find($parts[3]);
        $owner = $resource instanceof Process ? $resource->owner : null;
        $node = match ($parts[2]) {
            'node' => (int) $parts[3], 'schedule' => $resource?->getAttribute('host_node_id'),
            'process' => $owner instanceof Node ? $owner->id : $owner?->getAttribute('node_id'),
            default => $resource?->getAttribute('node_id'),
        };
        $observed = $row->last_seen ?? $row->filed_at;
        if (is_int($node) && $observed !== null) {
            $row->evidence = [...$row->evidence, 'doctor_node' => (string) $node, 'doctor_observed_at' => $observed->toISOString()];
        }
    }

    private function identity(ReleaseAlertKind $kind, string $target, Activity $alert): ?ReleaseIncident
    {
        $at = $alert->created_at;
        if ($at === null) {
            return null;
        }
        $id = data_get($alert->properties, 'subject.release_id');
        if ($kind === ReleaseAlertKind::InstanceDeployStalled && str_starts_with($target, 'instance:')) {
            $sha = data_get($alert->properties, 'subject.sha');

            return is_string($sha) && preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1 ? new ReleaseIncident($target, 'pending:'.$sha, $kind->value) : null;
        }
        if (! is_string($id)) {
            return null;
        }
        if ($target === 'machine-recipe-upcloud' && preg_match('/\Arecipe-build-(\d+)\z/', $id, $match) === 1) {
            $build = UpCloudTaskVmImageBuild::query()->find((int) $match[1]);

            return $build instanceof UpCloudTaskVmImageBuild ? new ReleaseIncident('upcloud:'.$build->recipe_identity, (string) $build->id, 'template_unverified') : null;
        }
        if ($target === 'machine-recipe' && preg_match('/\Arecipe-build-(\d+)\z/', $id, $match) === 1) {
            $build = TaskVmImageBuild::query()->find((int) $match[1]);
            if ($build instanceof TaskVmImageBuild && $build->recipe_identity !== null && $build->recipe_generation !== null
                && data_get($build->fleet_convergence, 'message') === data_get($alert->properties, 'summary')) {
                return new ReleaseIncident('recipe-fleet:'.$build->recipe_identity, $build->recipe_generation.':'.$build->id.':'.$build->attempt, 'convergence_halted');
            }

            return null;
        }
        if ($target === 'fleet' && preg_match('/\Afleet-rollout-(\d+)\z/', $id, $match) === 1) {
            $rollout = FleetRollout::query()->find((int) $match[1]);
            $summary = data_get($alert->properties, 'summary');
            if (! $rollout instanceof FleetRollout || ! is_string($summary)) {
                return null;
            }
            if ($kind === ReleaseAlertKind::RolloutCliFallback || ($kind === ReleaseAlertKind::RolloutStalled && str_starts_with($summary, 'Fleet rollout '.$rollout->id.' has waited since '))) {
                return new ReleaseIncident('fleet', (string) $rollout->id, $kind->value);
            }
            foreach ($rollout->nodes()->get() as $node) {
                if ($node->node_id === null) {
                    continue;
                }
                if ($kind === ReleaseAlertKind::RolloutCaddySkipped && str_starts_with($summary, 'Fleet rollout '.$rollout->id.' kept the live Caddyfile on node '.$node->node_name.': ')) {
                    return new ReleaseIncident('fleet-node:'.$node->node_id, (string) $rollout->id, $kind->value);
                }
                $expected = sprintf('Fleet rollout %d halted on node %s at step %s: %s', $rollout->id, $node->node_name, $node->step ?? 'unknown', $node->message ?? (string) $node->error_code);
                if ($kind === ReleaseAlertKind::RolloutHalted && $summary === $expected && $node->updated_at?->lte($at)) {
                    return new ReleaseIncident('fleet-node:'.$node->node_id, (string) $rollout->id, $node->error_code ?? $rollout->error_code ?? $kind->value);
                }
            }

            return null;
        }
        if ($target === 'gateway' && ctype_digit($id)) {
            $record = GatewayRelease::query()->find((int) $id);
            if (! $record instanceof GatewayRelease) {
                return null;
            }
            $summary = data_get($alert->properties, 'summary');
            if ($kind === ReleaseAlertKind::ReleasePaused && is_string($summary) && preg_match('/\AAutomatic releases are paused \(([a-z_]+)\) after release /', $summary, $reason) === 1) {
                return new ReleaseIncident('gateway', $id, $reason[1]);
            }
            if ($kind === ReleaseAlertKind::ReleaseGatewayAgentFailed && is_string($summary) && preg_match('/\(([a-z0-9._]+)\): /', $summary, $code) === 1) {
                return new ReleaseIncident('gateway', $id, $code[1]);
            }
            $fault = match ($kind) {
                ReleaseAlertKind::ReleaseSchedulerSilent => 'scheduler_silent',
                ReleaseAlertKind::ReleaseCleanupPaused => $record->error_code ?? $kind->value,
                ReleaseAlertKind::ReleaseFailed => in_array($record->outcome, ['failed', 'switched_back', 'interrupted'], true) && $record->updated_at?->lte($at) ? $record->error_code : null,
                default => null,
            };

            return is_string($fault) ? new ReleaseIncident('gateway', $id, $fault) : null;
        }
        if (str_starts_with($target, 'instance:') && preg_match('/\Adeployment-(\d+)\z/', $id, $match) === 1) {
            $record = InstanceDeployment::query()->find((int) $match[1]);

            return $record instanceof InstanceDeployment && $target === 'instance:'.$record->instance_id
                ? new ReleaseIncident($target, (string) $record->id, $record->error_code ?? $kind->value) : null;
        }

        return null;
    }

    private function unmodified(Task $task): bool
    {
        $created = $task->getAttribute('created_at');
        $updated = $task->getAttribute('updated_at');

        return $created instanceof Carbon && $updated instanceof Carbon && $created->eq($updated);
    }

    private function hashedDoctor(ProblemFingerprint $row): bool
    {
        return $row->source === ProblemSource::Doctor && preg_match('/\Adoctor#[0-9a-f]{12}\z/', $row->fingerprint) === 1;
    }

    private function generated(ProblemFingerprint $row, Task $group): bool
    {
        if ($group->preview !== false || $group->workspace()->exists() || $group->checks()->exists() || $group->pr_url !== null || $group->pr_branch !== null || $group->started_at !== null || $group->implementer_agent_thread_id !== null || $group->reviewer_agent_thread_id !== null || $group->status->value !== 'backlog' || ! $this->unmodified($group) || TaskComment::query()->where('task_group_id', $group->id)->exists()) {
            return false;
        }
        $tasks = $group->tasks()->orderBy('position')->lockForUpdate()->get();
        if ($tasks->count() !== 2) {
            return false;
        }
        $ids = [$group->id, ...$tasks->modelKeys()];
        foreach ($ids as $id) {
            if (Activity::query()->whereIn('properties->path', ['api/task-groups/'.$id, 'api/task-groups/'.$group->id.'/tasks/'.$id])->whereNotIn('command', ['tasks:show', 'tasks:list'])->exists()) {
                return false;
            }
        }
        $sections = explode("\n\n", (string) $group->brief);
        $summary = $row->evidence['summary'] ?? null;
        $parts = explode('|', $row->fingerprint);
        if (! is_string($summary) || (count($parts) !== 4 && ! $this->hashedDoctor($row)) || count($sections) !== 9 || $sections[0] !== 'Filed by the outer loop.'
            || $sections[1] !== "Symptom\n".$summary || $sections[2] !== "Fingerprint\n".$row->fingerprint) {
            return false;
        }
        $title = match (true) {
            $this->hashedDoctor($row) => $row->fingerprint,
            $row->source === ProblemSource::Doctor => "Doctor {$parts[1]} on {$parts[2]} {$parts[3]}",
            default => (ReleaseAlertKind::tryFrom($parts[1])?->label() ?? $parts[1])." for {$parts[2]} at ".substr($parts[3], 0, 12),
        };
        $repository = $row->evidence['release_repository'] ?? '';
        $releaseId = $row->evidence['release_id'] ?? null;
        if (! is_string($repository) || ($releaseId !== null && ! is_string($releaseId))) {
            return false;
        }
        $entry = match (true) {
            $this->hashedDoctor($row) => $row->fingerprint,
            $row->source === ProblemSource::Doctor => "{$parts[2]} {$parts[3]} {$parts[1]}",
            default => $repository.'@'.$parts[3].($releaseId !== null ? ', release '.$releaseId : ''),
        };
        if ($group->title !== $title || $sections[8] !== "Suspected entry point\n".$entry
            || preg_match('/\AFirst seen\n\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC\z/', $sections[3]) !== 1
            || preg_match('/\ALast seen\n\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC\z/', $sections[4]) !== 1
            || preg_match('/\ACount\n[1-9]\d*\z/', $sections[5]) !== 1
            || preg_match('/\AOccurrences\n(?:\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC [1-9]\d*\n?)+\z/', $sections[6]) !== 1) {
            return false;
        }
        if ($row->source === ProblemSource::Release && ! $this->originalReleaseBody($row, $group, $sections)) {
            return false;
        }
        foreach ($tasks as $index => $task) {
            $expected = self::HistoricalSymptomSubtasks[$index];
            if (! $this->unmodified($task) || $task->position !== $index + 1 || $task->status->value !== 'todo' || $task->title !== $expected[0] || ! in_array($task->brief, [$summary, self::HistoricalInstructionBriefs[$index]], true)
                || $task->deliverables !== [['id' => $expected[1], 'type' => 'review', 'description' => $expected[2]]]
                || $task->topology->toArray() !== [] || $task->started_at !== null || $task->implementer_agent_thread_id !== null || $task->reviewer_agent_thread_id !== null || $task->pr_url !== null || $task->subtask_start_commit !== null || $task->checks()->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $sections */
    private function originalReleaseBody(ProblemFingerprint $row, Task $group, array $sections): bool
    {
        $from = substr($sections[3], strlen("First seen\n"), -4);
        $alerts = Activity::query()->where('command', 'release:alert')->where('properties->problem->fingerprint', $row->fingerprint)
            ->where('created_at', '>=', $from)->where('created_at', '<=', $group->getAttribute('created_at'))->orderBy('id')->get();
        if ($alerts->isEmpty()) {
            return false;
        }
        $blocks = [];
        $ids = [];
        $urls = [];
        foreach ($alerts as $alert) {
            $stamp = $alert->created_at;
            if ($stamp === null) {
                return false;
            }
            $block = intdiv($stamp->getTimestamp(), 300);
            if (! isset($blocks[$block])) {
                $blocks[$block] = ['at' => $stamp->utc()->format('Y-m-d H:i:s').' UTC', 'count' => 0];
            }
            $blocks[$block]['count']++;
            $ids[] = $alert->id;
            $url = data_get($alert->properties, 'evidence_url');
            if (is_string($url) && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }
        $first = $alerts->first();
        $last = $alerts->last();
        $history = array_map(static fn (array $block): string => $block['at'].' '.$block['count'], array_slice(array_values($blocks), -20));
        $evidence = ['Activity ids: '.implode(', ', array_slice($ids, -5))];
        if ($urls !== []) {
            $evidence[] = 'Evidence links: '.implode(', ', array_slice($urls, -5));
        }
        $summary = data_get($last->properties, 'summary');

        return is_string($summary) && $sections[1] === "Symptom\n".$summary
            && $sections[3] === "First seen\n".$first->created_at?->utc()->format('Y-m-d H:i:s').' UTC'
            && $sections[4] === "Last seen\n".$last->created_at?->utc()->format('Y-m-d H:i:s').' UTC'
            && $sections[5] === "Count\n".count($blocks)
            && $sections[6] === "Occurrences\n".implode("\n", $history)
            && $sections[7] === "Evidence\n".implode("\n", $evidence);
    }

    private function mergeEpisode(ProblemFingerprint $canonical, ProblemFingerprint $incoming): void
    {
        $evidence = app(ProblemEvidence::class);
        $windows = [];
        $blocks = [];
        $samples = [];
        foreach ([$canonical, $incoming] as $row) {
            $settled = $evidence->legacyEpisode($row->evidence, $row->source);
            $sample = $settled['evidence'] ?? $row->evidence;
            $samples[] = $sample;
            $blocks = [...$blocks, ...$evidence->ints($sample['counted_blocks'] ?? [])];
            $counts = $evidence->ints($sample['observation_counts'] ?? []);
            $times = $sample['observation_times'] ?? [];
            if (! is_array($times)) {
                continue;
            }
            foreach (array_values($times) as $index => $time) {
                if (! is_string($time)) {
                    continue;
                }
                $at = Carbon::parse($time);
                $block = intdiv($at->getTimestamp(), 300);
                $previous = $windows[$block] ?? null;
                $windows[$block] = ['at' => $previous === null || $time < $previous['at'] ? $time : $previous['at'], 'count' => ($previous['count'] ?? 0) + ($counts[$index] ?? 1)];
                $blocks[] = $block;
            }
        }
        ksort($windows);
        $windows = array_slice(array_values($windows), -20);
        $blocks = array_values(array_unique($blocks));
        sort($blocks);
        $canonical->evidence = [...$evidence->apply($samples[0], $samples[1]),
            'observation_times' => array_column($windows, 'at'), 'observation_counts' => array_column($windows, 'count'), 'counted_blocks' => $blocks,
        ];
        $canonical->occurrences = count($blocks);
    }

    private function merge(ProblemFingerprint $canonical, ProblemFingerprint $row, ?Task $group): void
    {
        $primary = $canonical->task_group_id === null ? null : Task::topLevel()->lockForUpdate()->find($canonical->task_group_id);
        $open = $primary instanceof Task && ! in_array($primary->status->value, ['completed', 'cancelled', 'failed'], true);
        if ($canonical->task_group_id !== null && ! $open && $canonical->muted_until === null) {
            $canonical->muted_until = app(ProblemSuppression::class)->muteUntil($primary);
        }
        if ($row->task_group_id !== null && (! $group instanceof Task || in_array($group->status->value, ['completed', 'cancelled', 'failed'], true)) && $row->muted_until === null) {
            $row->muted_until = app(ProblemSuppression::class)->muteUntil($group);
        }
        $this->mergeEpisode($canonical, $row);
        if ($row->muted_until !== null && ($canonical->muted_until === null || $row->muted_until->gt($canonical->muted_until))) {
            $canonical->muted_until = $row->muted_until;
        }
        if ($row->first_seen !== null && ($canonical->first_seen === null || $row->first_seen->lt($canonical->first_seen))) {
            $canonical->first_seen = $row->first_seen;
        }
        if ($row->last_seen !== null && ($canonical->last_seen === null || $row->last_seen->gt($canonical->last_seen))) {
            $canonical->last_seen = $row->last_seen;
        }
        if (! $open && $group instanceof Task && ! in_array($group->status->value, ['completed', 'cancelled', 'failed'], true)) {
            $canonical->task_group_id = $row->task_group_id;
            $canonical->filed_at = $row->filed_at;
            $evidence = $canonical->evidence;
            unset($evidence['generated_group_hash']);
            $canonical->evidence = $evidence;
            if (isset($row->evidence['generated_group_hash'])) {
                $canonical->evidence = [...$canonical->evidence, 'generated_group_hash' => $row->evidence['generated_group_hash']];
            }
        } elseif ($group instanceof Task && app(ProblemGroupSnapshot::class)->unchanged($group, $row->evidence['generated_group_hash'] ?? null)) {
            DB::table('tasks')->where('id', $group->id)->orWhere('parent_id', $group->id)->update(['status' => 'cancelled', 'settled_at' => now(), 'updated_at' => now()]);
        }
        $canonical->save();
        $row->delete();
    }
};
