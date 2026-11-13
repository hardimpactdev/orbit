<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Domain\ConnInstanceWorkState;

/** Conn consent precedes the existing forced engine; no request probes the container. */
final readonly class DestroyConnInstanceAction
{
    public function __construct(private StartInstanceDestroyAction $destroy, private ConnInstanceWorkState $work) {}

    public function execute(Instance $instance, bool $acknowledgeUnsaved, Node $caller, string $requestId): Operation
    {
        if ($instance->name === DefaultInstanceName::Name) {
            throw new ResourceOperationException('instance.default_protected', 'The default Instance cannot be removed from Conn.', 409);
        }
        if (! $instance->runtime()->exists()) {
            throw new ResourceOperationException('instance.not_container', 'Conn removes only container Instances.', 409);
        }
        if (! $acknowledgeUnsaved) {
            $this->work->assertRemovalSafe($instance);
        }

        app(ContainerInstanceDestroy::class)->assertAvailable($instance, true);
        app(DirectInstanceDestroyIntent::class)->assertAvailable($this->destroy->snapshot($instance, true));
        $operation = $this->destroy->execute($instance, true, 'node:'.$caller->id, $requestId);

        return DB::transaction(function () use ($operation, $caller): Operation {
            $recorded = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $recorded->update(['state' => [...($recorded->state ?? []), 'conn_destroy_caller_node_id' => $caller->id]]);

            return $recorded;
        });
    }
}
