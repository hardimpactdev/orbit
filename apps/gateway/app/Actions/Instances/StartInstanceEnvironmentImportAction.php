<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Operations\OperationIdempotency;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Admit one env:import task; Swamp copies the Node's .env into its vault and the projection stores it. */
final readonly class StartInstanceEnvironmentImportAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private InstanceEnvironmentContextResolver $contexts) {}

    public function execute(Instance $instance, bool $replace, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.env_import')) !== null) {
            return $replay;
        }
        InstanceContainer::assertCheckout($instance);

        return DB::transaction(function () use ($instance, $replace, $caller, $requestId): Operation {
            $context = $this->contexts->resolve($instance->refresh(), true);
            $input = ['instance_id' => $instance->id, 'replace' => $replace,
                'direct_import' => ['node_id' => $context->nodeId, 'base' => $context->path, 'user' => $context->executionUser]];

            return $this->operations->execute('instance.env_import', 'instance', $instance->id, $instance->node_id,
                $input, $caller, $requestId);
        });
    }
}
