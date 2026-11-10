<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Instances\Environment\ObservedInstanceEnvironment;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class StartInstanceEnvironmentSyncAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private InstanceEnvironmentContextResolver $contexts, private NativeInstanceEnvironmentSync $sync, private ObservedInstanceEnvironment $observed) {}

    /** `$force` replaces a file whose keys the Node agent has not reported, or that holds keys stored configuration lacks. */
    public function execute(Instance $instance, ?string $caller = null, ?string $requestId = null, bool $force = false): Operation
    {
        if (($replay = $this->idempotency->replay('instance.sync_environment')) !== null) {
            return $replay;
        }
        InstanceContainer::assertCheckout($instance);
        if (app(DirectOperation::class)->definition('instance.sync_environment') === null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Native environment synchronization is unavailable.', 409);
        }

        return DB::transaction(function () use ($instance, $caller, $requestId, $force): Operation {
            $context = $this->contexts->resolve($instance->refresh(), true);
            if (! $instance->environmentValues()->exists()) {
                throw new ResourceOperationException('env.configuration_missing', 'Import or store environment configuration before synchronization.', 409);
            }
            $testingSkip = $this->observed->admit($instance, $context, $force);
            $input = ['instance_id' => $instance->id, 'direct_environment' => $this->sync->plan($context, testingSkip: $testingSkip)];

            return $this->operations->execute('instance.sync_environment', 'instance', $instance->id, $instance->node_id,
                $input, $caller, $requestId);
        });
    }
}
