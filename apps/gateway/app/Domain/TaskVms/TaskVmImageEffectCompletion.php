<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Projects proven child effects onto the image attempt; it never advances or dispatches work. */
final readonly class TaskVmImageEffectCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->target_type !== 'task_vm_image' || $outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        DB::transaction(function () use ($operation, $outcome): void {
            $attempt = $operation->input['attempt'];
            $changed = TaskVmImageBuild::query()->whereKey($operation->target_id)->where('attempt', $attempt)
                ->where('state', TaskVmImageBuildState::Running)->whereNull('cleanup_started_at')->update(['attempt' => $attempt]);
            if ($changed !== 1) {
                return;
            }
            $build = TaskVmImageBuild::query()->findOrFail($operation->target_id);
            $phase = ValidatedData::string($operation->input['phase']);
            $step = match ($phase) {
                'promote' => TaskVmImageBuildStep::Promote, 'prune' => TaskVmImageBuildStep::Prune, default => TaskVmImageBuildStep::Recipe
            };
            $program = $step === TaskVmImageBuildStep::Recipe ? 'base:'.$phase : null;
            $steps = $build->steps;
            $index = array_find_key($steps, static fn (array $entry): bool => $entry['step'] === $step->value && $entry['program'] === $program) ?? count($steps);
            $entry = $steps[$index] ?? ['step' => $step->value, 'program' => $program, 'started_at' => $operation->started_at?->toISOString() ?? now()->toISOString(), 'seconds' => null, 'error' => null];
            $entry['started_at'] ??= $operation->started_at?->toISOString() ?? now()->toISOString();
            $entry['operations'][ValidatedData::string($operation->input['effect_key'])] = $operation->id;
            if (isset($outcome->result['boot_id'])) {
                $entry['phase_state'] = ['boot_id' => $outcome->result['boot_id'], 'attempt' => $attempt];
            }
            $last = [
                'reset' => ['reset-smoke', 'task_vm.image_remove'], 'launch' => ['launch', 'task_vm.image_launch'],
                'boot' => ['boot-confirmed', TaskVmCommandContracts::guestKind('guest.cloud-init')],
                'prepare' => ['boot-policy', TaskVmCommandContracts::guestKind('image.boot')],
                'clean' => ['clean', TaskVmCommandContracts::guestKind('image.clean')],
                'publish' => ['delete-builder', 'task_vm.image_remove'], 'smoke' => ['smoke-verify', TaskVmCommandContracts::guestKind('image.verify')],
                'topology-seed' => ['finish-seed', 'task_vm.image_finish_seed'], 'promote' => ['promote', 'task_vm.image_promote'], 'prune' => ['prune', 'task_vm.image_prune'],
            ][$phase] ?? [$phase.'-confirmed', 'task_vm.image_phase_confirm'];
            if (($operation->input['effect_key'] ?? null) === $last[0] && $operation->kind === $last[1]) {
                $entry['seconds'] = max(0, now()->getTimestamp() - Carbon::parse($entry['started_at'])->getTimestamp());
                $entry['error'] = null;
            }
            $steps[$index] = $entry;
            if ($operation->kind === 'task_vm.image_publish' && is_string($outcome->result['fingerprint'] ?? null)) {
                $host = app(TaskVmSettings::class)->host($build->host_node_id);
                $alias = $build->images['base']['alias'] ?? RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, 'base', $build->id);
                $build->images = ['base' => ['project' => $host->project, 'alias' => $alias, 'fingerprint' => $outcome->result['fingerprint'], 'type' => 'virtual-machine']];
                $build->image_fingerprint = $outcome->result['fingerprint'];
            }
            if ($operation->kind === 'task_vm.image_promote') {
                $build->promoted_at ??= now();
            }
            $build->forceFill(['steps' => $steps, 'current_step' => $step, 'current_program' => $program])->save();
        });
    }
}
