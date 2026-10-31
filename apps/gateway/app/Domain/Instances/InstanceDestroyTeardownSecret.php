<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupEndingIntent;
use App\Models\Instance;
use App\Models\Operation;

/** Resolves admitted teardown scripts only in the owning Node request memory. */
final readonly class InstanceDestroyTeardownSecret implements OperationSecretSource
{
    public function __construct(private ProjectLifecycleStepStore $steps) {}

    public function name(): string
    {
        return 'instance.destroy_teardown';
    }

    public function targetTypes(): array
    {
        return ['instance', 'task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $input = $this->input($operation);
        if ($input === null || ! is_int($input['instance_id'] ?? null)
            || $operation->node_id !== ($input['node_id'] ?? null)
            || ($input['environment'] ?? null) !== 'development') {
            return null;
        }
        $instance = Instance::query()->find($input['instance_id']);

        return $instance !== null && $instance->project_id === ($input['project_id'] ?? null)
            && $instance->node_id === $operation->node_id ? $instance->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $key !== ''
            || $parameter !== ($operation->target_type === 'task_group' ? 'destruction.teardown' : 'teardown')) {
            $this->refuse();
        }
        $input = $this->input($operation);
        if ($input === null || ! is_int($input['project_id'] ?? null)) {
            $this->refuse();
        }
        $projectId = $input['project_id'];
        $destroy = $input['direct_destroy'] ?? null;
        $user = is_array($destroy) ? ($destroy['node_user'] ?? null) : null;
        $admitted = is_array($destroy) ? ($destroy['teardown'] ?? null) : null;
        if (! is_string($user) || $user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1
            || ! is_array($admitted) || ! array_is_list($admitted) || $admitted === []) {
            $this->refuse();
        }
        $seen = [];
        $members = [];
        $redactions = [];
        foreach ($admitted as $member) {
            if (! is_array($member) || ! is_int($member['instance_id'] ?? null)
                || isset($seen[$member['instance_id']]) || ! is_array($member['steps'] ?? null)) {
                $this->refuse();
            }
            $instance = Instance::query()->with(['project', 'node'])->find($member['instance_id']);
            if ($instance === null || $instance->project_id !== $projectId
                || $instance->node_id !== $operation->node_id || $instance->checkout_path !== ($member['checkout'] ?? null)
                || ! $instance->placedOnAppDev() || $instance->node->user !== $user || ($member['user'] ?? null) !== $user) {
                $this->refuse();
            }
            $seen[$instance->id] = true;
            $resolved = [];
            if ($member['steps'] !== []) {
                $steps = $this->steps->ordered($instance->project, LifecyclePhase::Teardown);
                $identities = array_map(static fn ($step): array => [
                    'name' => $step->name, 'timeout_seconds' => $step->timeoutSeconds, 'sha256' => hash('sha256', $step->command),
                ], $steps);
                if ($identities !== $member['steps']) {
                    $this->refuse();
                }
                foreach ($steps as $step) {
                    $resolved[] = ['name' => $step->name, 'timeout_seconds' => $step->timeoutSeconds, 'command' => $step->command];
                    $redactions[] = $step->command;
                }
            }
            $members[] = ['instance_id' => $instance->id, 'steps' => $resolved];
        }
        if (! isset($seen[$id])) {
            $this->refuse();
        }

        return new OperationSecretValue(['members' => $members], $redactions);
    }

    /** @return array<string, mixed>|null */
    private function input(Operation $operation): ?array
    {
        if ($operation->target_type === 'task_group') {
            return TaskGroupEndingIntent::admittedDestruction($operation);
        }

        return $operation->kind === 'instance.destroy' && $operation->target_type === 'instance'
            && $operation->target_id === ($operation->input['instance_id'] ?? null) ? $operation->input : null;
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'Teardown requires the unchanged admitted member scripts and placement.', 422);
    }
}
