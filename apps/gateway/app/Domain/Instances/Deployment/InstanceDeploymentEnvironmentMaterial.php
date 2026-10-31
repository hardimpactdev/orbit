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

/** Existing environment material, resolved in private request memory for the owning Instance. */
final readonly class InstanceDeploymentEnvironmentMaterial
{
    public function __construct(
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceTestEnvironment $testing,
    ) {}

    /** @return array{base:string,user:string,environment:string,domain:?string} */
    public function capture(Instance $instance): array
    {
        return $this->target($this->contexts->resolve($instance, requireActiveNode: true, lockRoute: true));
    }

    /** @return array<string,mixed> Private values never enter Operation input or result. */
    public function read(Operation $operation): array
    {
        $instance = Instance::query()->findOrFail($operation->target_id);
        $context = $this->contexts->resolve($instance, requireActiveNode: true);
        $target = $this->target($context);
        $admitted = $operation->input['environment'] ?? null;
        if (! is_array($admitted) || count($admitted) !== count($target)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The environment target changed after deployment admission.', 422);
        }
        foreach ($target as $key => $value) {
            if (! array_key_exists($key, $admitted) || $admitted[$key] !== $value) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The environment target changed after deployment admission.', 422);
            }
        }
        $snapshot = $this->store->synchronizationSnapshot($context);
        $testing = $this->testing->plan($context->instanceId, $snapshot->values());

        return [...$this->target($context), 'required_capacity' => $this->store->synchronizationCapacity($context),
            'values' => $snapshot->values(), 'owned_keys' => $instance->environment_owned_keys ?? [],
            'testing' => $testing === null ? null : ['values' => $testing->values, 'managed_keys' => $testing->managedKeys]];
    }

    /** @return array{base:string,user:string,environment:string,domain:?string} */
    private function target(InstanceEnvironmentContext $context): array
    {
        return ['base' => $context->path, 'user' => $context->executionUser,
            'environment' => $context->environment, 'domain' => $context->routeDomain];
    }
}
