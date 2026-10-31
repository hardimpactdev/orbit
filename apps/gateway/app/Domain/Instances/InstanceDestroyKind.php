<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;

/** Select from stored placement and ownership; callers cannot choose a writer. */
final class InstanceDestroyKind
{
    public static function forInstance(Instance $instance): string
    {
        $accepted = InstanceRemovalMember::query()->where('instance_id', $instance->id)->whereNull('row_deleted_at')->with('removal')->first();
        if ($accepted !== null) {
            return $accepted->removal->native_intent === null ? 'instance.destroy' : 'instance.destroy_development';
        }
        $owners = Instance::query()->select('id')->whereKey($instance->id);
        if ($instance->source_layout === InstanceSourceLayout::Checkout->value) {
            // A force checkout removal can include its registered worktrees.
            $owners->orWhere(fn ($query) => $query->where('project_id', $instance->project_id)
                ->where('node_id', $instance->node_id)->where('source_layout', InstanceSourceLayout::Worktree->value));
        }

        return $instance->placedOnAppDev()
            && ! DatabaseConnection::query()->whereIn('owner_instance_id', $owners)->whereNotNull('database_server_id')->exists()
            ? 'instance.destroy_development' : 'instance.destroy';
    }

    public static function forRollback(Operation $parent): string
    {
        $kind = $parent->children()->where('child_key', 'rollback-removal')->value('kind');
        if ($kind !== null) {
            if (! in_array($kind, ['instance.destroy', 'instance.destroy_development'], true)) {
                throw new ResourceOperationException('operation.child_unproven', 'The recorded rollback kind changed.', 409);
            }

            return $kind;
        }

        return self::forInstance(Instance::query()->findOrFail($parent->target_id));
    }
}
