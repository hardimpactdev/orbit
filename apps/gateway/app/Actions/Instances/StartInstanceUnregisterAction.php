<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\InstanceRemovalKind;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/**
 * Orbit forgets a host checkout Instance and keeps its folder (docs/reference/instance-removal.md#unregister-a-host-checkout).
 * Its own refusals come first; the rest is destroy's admission, with no source, teardown or database step.
 */
final readonly class StartInstanceUnregisterAction
{
    public const string Kind = 'instance.unregister';

    public function __construct(private StartInstanceDestroyAction $destroy, private OperationIdempotency $idempotency) {}

    public function execute(Instance $instance, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay(self::Kind)) !== null) {
            return $replay;
        }
        ContainerInstanceDestroy::assertReleaseRemovalAvailable($instance);
        if ($instance->runtime()->exists()) {
            throw new ResourceOperationException('instance.unregister_container', "Instance [{$instance->name}] is a container Instance. Use instance:destroy.", 409);
        }
        if (! $instance->placedOnAppDev()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Only a host checkout development Instance can be unregistered.', 503);
        }
        // Unregister never drops a database, and no database may stay without an owner: the server databases destroy would drop refuse.
        if (app(PrepareOwnedDatabaseDestructionAction::class)->snapshot($instance)['servers'] !== []) {
            throw new ResourceOperationException('instance.unregister_owned_database', "Instance [{$instance->name}] owns a database on a Database server. Use instance:destroy.", 409);
        }
        app(ContainerInstanceDestroy::class)->assertAvailable($instance, false);

        return $this->destroy->admit($instance, $this->destroy->snapshot($instance, false, InstanceRemovalKind::Unregister), self::Kind, $caller, $requestId);
    }
}
