<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCommandContracts;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Each invocation is one phase of one image; all artifacts are held until the complete set passes smoke and has its
 * topology seed. `smoke` keeps its VM for `topology-seed`, which builds the seed in it and then deletes it.
 */
final readonly class RecipeImageBuildSteps
{
    public function __construct(private TaskVmSettings $settings, private IncusHost $incus) {}

    public function assertCurrent(TaskVmImageBuild $build): void
    {
        $this->requireServingDelivery($build);
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->recipe_generation) {
            throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This image attempt cannot continue.');
        }
    }

    public function assertPromotion(TaskVmImageBuild $build): void
    {
        $this->requireServingDelivery($build);
        DB::transaction(function () use ($build): void {
            $current = DB::table('task_vm_image_recipe')->where('id', 1)->where('identity', $build->recipe_identity)
                ->where('generation', $build->recipe_generation)->update(['identity' => $build->recipe_identity]);
            if ($current !== 1) {
                throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This build cannot become current.');
            }
            $passed = static fn (string $program): bool => array_any($build->steps, static fn (array $entry): bool => $entry['program'] === $program && $entry['seconds'] !== null && $entry['error'] === null);
            if (! isset($build->images['base']) || ! $passed('base:smoke') || ! $passed('base:topology-seed')) {
                throw new TaskVmException('task_vm.image_build_failed', 'The recipe base must pass smoke and have its topology seed before promotion.');
            }
            $proofs = [];
            foreach ($build->steps as $entry) {
                $proofs += $entry['operations'] ?? [];
            }
            foreach (['smoke-check' => TaskVmCommandContracts::guestKind('image.check'), 'smoke-verify' => TaskVmCommandContracts::guestKind('image.verify'), 'finish-seed' => 'task_vm.image_finish_seed'] as $key => $kind) {
                $proof = isset($proofs[$key]) ? Operation::query()->find($proofs[$key]) : null;
                if ($proof === null || ! is_int($proof->getAttribute('parent_operation_id'))) {
                    throw new ResourceOperationException('operation.completion_unproven', 'The image proof has no stamped parent.', 409);
                }
                $proofParent = Operation::query()->findOrFail(ValidatedData::integer($proof->getAttribute('parent_operation_id')));
                $verified = app(OperationChildren::class)->requireSucceeded($proofParent, $key);
                if ($verified->id !== $proof->id || $proof->kind !== $kind || $proof->target_type !== 'task_vm_image' || $proof->target_id !== $build->id || $proof->node_id !== $build->host_node_id || ($proof->result['ready'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.completion_unproven', "Smoke and seed require this image's proven child Operations.", 409);
                }
                if ($key === 'finish-seed') {
                    if ((ValidatedData::object($proof->input['operation'] ?? [])['fingerprint'] ?? null) !== $build->image_fingerprint) {
                        throw new ResourceOperationException('operation.completion_unproven', 'The seed proof names another image.', 409);
                    }
                } else {
                    $launched = app(OperationChildren::class)->requireSucceeded($proofParent, 'launch-smoke');
                    if ((ValidatedData::object($launched->input['operation'] ?? [])['image'] ?? null) !== $build->image_fingerprint) {
                        throw new ResourceOperationException('operation.completion_unproven', 'The smoke proof booted another image.', 409);
                    }
                }
            }
            $published = TaskVmImageEvidence::published($build);
            if ($published === null) {
                throw new ResourceOperationException('operation.completion_unproven', 'The candidate has no successful publication Operation.', 409);
            }
            $publisher = Operation::query()->findOrFail(ValidatedData::integer($published->getAttribute('parent_operation_id')));
            $publication = app(OperationChildren::class)->requireSucceeded($publisher, 'publish');
            if ($publication->id !== $published->id) {
                throw new ResourceOperationException('operation.completion_unproven', 'The publication proof names another candidate.', 409);
            }

        });
    }

    private function requireServingDelivery(TaskVmImageBuild $build): void
    {
        $recipe = new RecipeLoader()->decode((string) $build->recipe_json);
        if ($recipe->agentDigest((string) $build->hostNode->architecture) !== app(AgentDelivery::class)->pin($build->hostNode)) {
            throw new TaskVmException(TaskVmError::ImageRecipeSuperseded, 'The serving recipe delivery changed. This build cannot continue or promote.');
        }
    }

    private function alias(TaskVmImageBuild $build, string $profile): string
    {
        return $build->images[$profile]['alias'] ?? RecipeImageBuilds::alias($this->settings->host($build->host_node_id)->imageAlias, (string) $build->recipe_identity, $profile, $build->id);
    }

    public function resumabilityError(TaskVmImageBuild $build): ?string
    {
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->recipe_generation) {
            return 'A newer recipe is desired. Start the current recipe instead.';
        }
        if (in_array($build->current_step, [TaskVmImageBuildStep::Promote, TaskVmImageBuildStep::Prune], true)) {
            return null;
        }
        [$profile, $phase] = explode(':', (string) $build->current_program, 2) + ['', ''];
        $host = $this->settings->host($build->host_node_id);
        $published = $this->incus->aliasTarget($host, $build->hostNode, $this->alias($build, $profile));
        if (in_array($phase, ['publish', 'smoke', 'topology-seed'], true) && $published !== null) {
            return null;
        }
        if ($phase === 'launch' || array_key_exists((string) $build->builder_vm_name, $this->incus->instanceImages($host, $build->hostNode))) {
            return null;
        }

        return 'The failed recipe build resources no longer exist.';
    }
}
