<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Instances\StartInstanceCompleteAction;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

enum ConnLaunchChild: string
{
    case Create = 'create_operation_id';
    case Wake = 'wake_operation_id';
    case Completion = 'completion_operation_id';
    case Destroy = 'destroy_operation_id';

    /** @return Builder<Operation> */
    public static function completions(): Builder
    {
        return Operation::query()->where('kind', StartInstanceCompleteAction::Kind)->where('target_type', 'instance');
    }

    public function accepts(string $kind): bool
    {
        return match ($this) {
            self::Create => $kind === 'instance.create',
            self::Wake => in_array($kind, ['instance.wake', 'instance.start'], true),
            self::Completion => $kind === StartInstanceCompleteAction::Kind,
            self::Destroy => $kind === 'instance.destroy',
        };
    }

    public static function instanceId(Operation $launch): ?int
    {
        $id = $launch->input['instance_id'] ?? $launch->state['launch_instance_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    public function operationId(Operation $launch): ?int
    {
        $id = Arr::get($launch->state ?? [], 'launch_children.'.$this->value.'.id');

        return is_int($id) ? $id : null;
    }

    public function operation(Operation $launch, bool $lock = false): ?Operation
    {
        $id = $this->operationId($launch);
        if ($id === null) {
            return null;
        }

        $child = Operation::query()->when($lock, static fn (Builder $query) => $query->lockForUpdate())->find($id);

        return $child !== null && $this->accepts($child->kind) && $child->node_id === $launch->node_id
            && $child->target_type === 'instance' && $child->target_id === self::instanceId($launch) ? $child : null;
    }
}
