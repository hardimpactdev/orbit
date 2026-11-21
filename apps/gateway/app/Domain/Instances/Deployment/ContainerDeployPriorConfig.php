<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProjectEnvironmentTemplate;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The configuration a container deployment's admission replaces with its target commit's: the Project's source facts and PHP selection,
 * the reference of its environment template, and the Instance's Process rows (docs/reference/deployments.md#development-defaults). The
 * admission keeps the earlier values in its input as `prior_config`; a run that ends at the probe, which keeps the checkout, restores them.
 * The template's entries can hold values, so the snapshot keeps only their hash.
 */
final class ContainerDeployPriorConfig
{
    /** The refusals of the start's probe, which ends the run before it changes anything. */
    public const array ProbeRefusals = ['deployment.commit_behind_checkout', 'deployment.checkout_dirty', 'deployment.branch_diverged', 'deployment.commit_not_on_branch'];

    private const array ProjectFacts = ['php_version', 'is_laravel', 'source_profile_error', 'source_facts_captured_at'];

    /** @return array<string, mixed> */
    public static function capture(Instance $instance): array
    {
        $project = $instance->project;
        $template = ProjectEnvironmentTemplate::query()->where('project_id', $project->id)->first();

        return [
            'project' => array_map(static fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value,
                $project->only(self::ProjectFacts)),
            'template' => $template === null ? null : [...$template->only(['source_path', 'source_commit', 'source_blob', 'checked_head']),
                'entries_sha256' => self::entriesHash($template)],
            'vite_port' => $instance->vite_port,
            'processes' => Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)->orderBy('id')->get()
                ->map(static fn (Process $process): array => $process->getRawOriginal())->all(),
        ];
    }

    /** Whether the run ended at the start's probe: a release's kept newer checkout, or one of the probe's refusals. */
    public static function endedAtProbe(string $status, bool $alreadyNewer, ?string $failedStep, ?string $errorCode): bool
    {
        return ($status === 'succeeded' && $alreadyNewer)
            || ($status === 'failed' && $failedStep === ContainerDeployOutcome::StartStep && in_array($errorCode, self::ProbeRefusals, true));
    }

    /**
     * Puts the admission's snapshot back, in the caller's transaction. Rows the admission created go, and rows it changed or removed
     * return as they were. A template whose entries the admission changed takes back its reference without its blob, so the next head
     * the Gateway reads downloads the file again.
     */
    public static function restore(Operation $operation, Instance $instance): void
    {
        $prior = $operation->input['prior_config'] ?? null;
        if (! is_array($prior)) {
            return;
        }
        $project = $instance->project;
        $project->forceFill(ValidatedData::object($prior['project']))->saveOrFail();
        $template = ProjectEnvironmentTemplate::query()->where('project_id', $project->id)->first();
        if (! is_array($prior['template'])) {
            $template?->delete();
        } elseif ($template !== null) {
            $reference = $prior['template'];
            $same = $reference['entries_sha256'] === self::entriesHash($template);
            unset($reference['entries_sha256']);
            $template->forceFill([...$reference, ...$same ? [] : ['source_blob' => null, 'checked_head' => $template->checked_head]])->saveOrFail();
        }
        $instance->forceFill(['vite_port' => $prior['vite_port']])->saveOrFail();
        $rows = array_map(ValidatedData::object(...), is_array($prior['processes']) ? array_values($prior['processes']) : []);
        $table = new Process()->getTable();
        DB::table($table)->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
            ->whereNotIn('id', array_column($rows, 'id'))->delete();
        foreach ($rows as $row) {
            DB::table($table)->updateOrInsert(['id' => $row['id']], $row);
        }
    }

    private static function entriesHash(ProjectEnvironmentTemplate $template): string
    {
        return hash('sha256', json_encode($template->entries, JSON_THROW_ON_ERROR));
    }
}
