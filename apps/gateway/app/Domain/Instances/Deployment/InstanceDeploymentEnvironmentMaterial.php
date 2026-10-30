<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Reads admitted record material only. Swamp inspects, renders and writes the local files. */
final readonly class InstanceDeploymentEnvironmentMaterial
{
    public function __construct(
        private InstanceDeploymentDirectInput $deployment,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceTestEnvironment $testing,
    ) {}

    /** @return array{base:string,user:string,environment:string,domain:?string} */
    public function target(Operation $operation): array
    {
        return $this->describe($this->context($operation));
    }

    /** @return array<string,mixed> Private request memory, never an Operation result. */
    public function read(Operation $operation): array
    {
        if ($operation->status->terminal() || $operation->cancel_requested_at !== null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The deployment no longer admits private material.', 409);
        }
        $context = $this->context($operation);
        $snapshot = $this->store->synchronizationSnapshot($context);
        $testing = $this->testing->plan($context->instanceId, $snapshot->values());

        return [...$this->describe($context), 'required_capacity' => $this->store->synchronizationCapacity($context),
            'values' => $snapshot->values(), 'owned_keys' => $snapshot->ownedKeys(),
            'testing' => $testing === null ? null : ['values' => $testing->values, 'managed_keys' => $testing->managedKeys]];
    }

    private function context(Operation $operation): InstanceEnvironmentContext
    {
        $this->deployment->input($operation);

        return $this->contexts->resolve(Instance::query()->findOrFail($operation->target_id), requireActiveNode: true);
    }

    /** @return array{base:string,user:string,environment:string,domain:?string} */
    private function describe(InstanceEnvironmentContext $context): array
    {
        return ['base' => $context->path, 'user' => $context->executionUser,
            'environment' => $context->environment, 'domain' => $context->routeDomain];
    }
}
