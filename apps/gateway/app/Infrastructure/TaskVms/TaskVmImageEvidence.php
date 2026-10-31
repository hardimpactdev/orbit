<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmCommandContracts;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;

/** Verifies existing child ancestry. Mutable build flags never prove an image effect. */
final class TaskVmImageEvidence
{
    /** @param array<string,int> $ids
     * @return array<string,int>
     */
    public static function preparation(TaskVmImageBuild $build, array $ids): array
    {
        $required = ['boot-confirmed' => TaskVmCommandContracts::guestKind('guest.cloud-init'), 'owner' => TaskVmCommandContracts::guestKind('image.owner'),
            'metadata' => 'task_vm.image_metadata', 'boot-policy' => TaskVmCommandContracts::guestKind('image.boot'), 'recipe-marker' => TaskVmCommandContracts::guestKind('image.recipe-marker'),
            'root-ca' => TaskVmCommandContracts::guestKind('image.trust-ca'), 'clean' => TaskVmCommandContracts::guestKind('image.clean')];
        foreach (['apt-source', 'packages', 'javascript', 'rust', 'agent'] as $phase) {
            $required[$phase.'-confirmed'] = 'task_vm.image_phase_confirm';
        }
        $recipe = app(RecipeLoader::class)->decode((string) $build->recipe_json);
        if (isset($recipe->tools['pi']->artifacts['amd64'])) {
            $required['pi-confirmed'] = 'task_vm.image_phase_confirm';
        }
        $proofs = [];
        foreach ($required as $key => $kind) {
            $proof = isset($ids[$key]) ? Operation::query()->find($ids[$key]) : null;
            if ($proof === null || $proof->kind !== $kind || $proof->target_type !== 'task_vm_image' || $proof->target_id !== $build->id || $proof->node_id !== $build->host_node_id || ($proof->result['ready'] ?? null) !== true) {
                self::refuse();
            }
            $parent = Operation::query()->findOrFail(ValidatedData::integer($proof->getAttribute('parent_operation_id')));
            $verified = app(OperationChildren::class)->requireSucceeded($parent, $key);
            if ($verified->id !== $proof->id) {
                self::refuse();
            }
            $proofs[$key] = $proof->id;
        }

        return $proofs;
    }

    public static function published(TaskVmImageBuild $build): ?Operation
    {
        return Operation::query()->where('kind', 'task_vm.image_publish')->where('target_type', 'task_vm_image')->where('target_id', $build->id)
            ->where('status', 'succeeded')->where('result->fingerprint', $build->image_fingerprint)->latest('id')->first();
    }

    private static function refuse(): never
    {
        throw new ResourceOperationException('operation.completion_unproven', 'Image publication requires the exact successful preparation children.', 409);
    }
}
