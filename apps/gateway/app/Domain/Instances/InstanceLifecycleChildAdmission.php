<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/**
 * The owning lifecycle admission adapter. Parent policy comes from stamped ancestry, never child input flags.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceLifecycleChildAdmission implements OperationChildAdmission
{
    /** @param ChildIntent $intent */
    public function admit(Operation $parent, array $intent): Operation
    {
        if ($parent->kind === 'route.target_set') {
            $instance = app(InstanceRouteRemovalAdmission::class)->instance($parent, $intent);

            return app(StartInstanceDestroyAction::class)->execute($instance, true, 'route.target_set', $parent->request_id);
        }
        if ($parent->kind !== 'instance.create' || $intent['key'] !== 'rollback-removal' || ! in_array($intent['kind'], ['instance.destroy', 'instance.destroy_development'], true)
            || $intent['node_id'] !== $parent->node_id || $intent['input'] !== ['instance_id' => $parent->target_id, 'force' => true]) {
            throw new ResourceOperationException('operation.child_forbidden', 'The rollback removal does not belong to this create.', 422);
        }

        return app(StartInstanceDestroyAction::class)->rollback($parent);
    }
}
