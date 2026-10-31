<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentMaterial;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class StartInstanceEnvironmentSyncAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private InstanceEnvironmentContextResolver $contexts, private NativeInstanceEnvironmentMaterial $material) {}

    public function execute(Instance $instance, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.sync_environment')) !== null) {
            return $replay;
        }
        if (app(DirectOperation::class)->definition('instance.sync_environment') === null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Native environment synchronization is unavailable.', 409);
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $context = $this->contexts->resolve($instance->refresh(), true);
            $input = ['instance_id' => $instance->id, 'direct_environment' => $this->material->forContext($context),
                'environment_material' => ['$secret' => 'instance.environment_sync', 'id' => $instance->id]];

            return $this->operations->execute('instance.sync_environment', 'instance', $instance->id, $instance->node_id,
                $input, $caller, $requestId);
        });
    }
}
