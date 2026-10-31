<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;

/** Existing environment values, delivered only to their exact native Instance parent. */
final readonly class NativeInstanceEnvironmentMaterial implements OperationSecretSource
{
    public function __construct(
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceTestEnvironment $testing,
        private ProcessInstanceOperation $processes,
    ) {}

    public function name(): string
    {
        return 'instance.environment_sync';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['instance.sync_environment', 'instance.create', 'instance.clone'], true)
            && $operation->target_type === 'instance'
            && ($operation->input['instance_id'] ?? null) === $operation->target_id
            ? $operation->target_id : null;
    }

    public function context(Operation $operation): InstanceEnvironmentContext
    {
        if ($this->ownerId($operation) === null) {
            $this->refuse();
        }
        $instance = Instance::query()->findOrFail($operation->target_id);
        if ($operation->node_id !== $instance->node_id) {
            $this->refuse();
        }
        $context = match ($operation->kind) {
            'instance.create' => $this->contexts->resolve($instance, true, createOperationId: $operation->id),
            'instance.clone' => $this->contexts->resolveForClone($instance, true),
            default => $this->contexts->resolve($instance, true),
        };
        if ($operation->kind === 'instance.sync_environment') {
            $saved = $operation->input['direct_environment'] ?? null;
            if (! is_array($saved) || ($saved['base'] ?? null) !== $context->path
                || ($saved['user'] ?? null) !== $context->executionUser
                || ($saved['environment'] ?? null) !== $context->environment
                || ($saved['node_id'] ?? null) !== $context->nodeId) {
                $this->refuse();
            }
        } else {
            $saved = $operation->input['direct_provisioning'] ?? null;
            if (! is_array($saved) || ($saved['environment'] ?? null) !== $context->environment
                || ($saved['user'] ?? null) !== $context->executionUser
                || ($saved['project_id'] ?? null) !== $context->projectId
                || ($saved['checkout'] ?? null) !== $instance->checkout_path) {
                $this->refuse();
            }
        }

        return $context;
    }

    /** Safe placement and fixed arguments; no environment contents or Node calls.
     * @return array<string,mixed>
     */
    public function plan(Operation $operation): array
    {
        return $this->forContext($this->context($operation), $operation->kind === 'instance.sync_environment');
    }

    /** @return array<string,mixed> */
    public function forContext(InstanceEnvironmentContext $context, bool $explicit = true): array
    {
        $instance = Instance::query()->findOrFail($context->instanceId);
        $runtime = null;
        if ($explicit && $context->environment === 'production') {
            $identity = $instance->selected_php_version === null ? null : ProductionPhpRuntimeIdentity::from($instance);
            $members = $this->processes->capture($instance, 'process.restart_instance')['members'];
            if (! is_array($members)) {
                throw new LogicException('The captured Process members must be a list.');
            }
            $processes = [];
            foreach ($members as $value) {
                $member = ValidatedData::object($value);
                $processes[] = ['id' => ValidatedData::integer($member['id']), 'input' => Arr::only(ValidatedData::object($member['input']), [
                    'process_id', 'agent_sha256', 'runtime', 'name', 'on_demand', 'directory', 'vite', 'vite_plan',
                ])];
            }
            $runtime = ['config' => $identity === null ? null : [
                'user' => $identity->user, 'home' => $identity->home, 'version' => $identity->version,
                'application' => $identity->applicationDirectory(),
            ], 'php' => $identity === null ? null : [
                'user' => $identity->user, 'home' => $identity->home, 'version' => $identity->version,
                'marker' => $identity->marker(), 'deadline_seconds' => 30,
            ], 'processes' => $processes];
        }

        return ['instance_id' => $context->instanceId, 'node_id' => $context->nodeId,
            'environment' => $context->environment, 'base' => $context->path, 'user' => $context->executionUser,
            'maximum' => InstanceEnvironmentValidator::MaximumFileBytes,
            'required_capacity' => InstanceEnvironmentValidator::MaximumFileBytes * 2,
            'preserve_workload_keys' => $explicit, 'runtime' => $runtime];
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== 'environment_material') {
            $this->refuse();
        }
        $instance = Instance::query()->with('node')->findOrFail($id);
        if (in_array($operation->kind, ['instance.create', 'instance.clone'], true)
            && in_array($instance->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared], true)) {
            $saved = $operation->input['direct_provisioning'] ?? null;
            $environment = $instance->placementEnvironment();
            $user = $environment === 'production' ? $instance->production_user : $instance->node->user;
            if ($operation->node_id !== $instance->node_id || ! is_array($saved)
                || ($saved['environment'] ?? null) !== $environment || ($saved['user'] ?? null) !== $user
                || ($saved['checkout'] ?? null) !== $instance->checkout_path
                || ($saved['project_id'] ?? null) !== $instance->project_id) {
                $this->refuse();
            }

            return new OperationSecretValue(null);
        }
        $context = $this->context($operation);
        $snapshot = $context->cloning ? $this->store->cloneSynchronizationSnapshot($context) : $this->store->synchronizationSnapshot($context);
        $values = $snapshot->values();
        $testing = $this->testing->plan($id, $values);
        $instance = Instance::query()->findOrFail($id);

        return new OperationSecretValue([
            'content' => $this->renderer->render($context, $values), 'keys' => array_keys($values),
            'owned_keys' => $instance->environment_owned_keys ?? [],
            'testing' => $testing === null ? null : ['content' => $this->renderer->render($context, $testing->values),
                'managed_keys' => $testing->managedKeys, 'test_database' => $testing->testDatabase],
        ], array_values($values));
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The native parent does not own this Instance environment.', 422);
    }
}
