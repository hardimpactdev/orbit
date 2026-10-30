<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Reads only the existing, admitted Plausible environment. Never generates or stores secrets. */
final readonly class AnalyticsRuntimeEnvironmentSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'analytics.runtime_environment';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'analytics.update' && $operation->target_type === 'node'
            && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== 'runtime_environment'
            || $operation->status !== OperationStatus::Running) {
            $this->refuse();
        }
        $assignmentId = $operation->input['assignment_id'] ?? null;
        if (! is_int($assignmentId) || ! NodeRole::query()->whereKey($assignmentId)->where('node_id', $id)
            ->where('role', RoleName::Analytics)->where('status', LifecycleStatus::Active)->exists()) {
            $this->refuse();
        }
        $processId = $operation->input['process_id'] ?? null;
        $process = is_int($processId) ? Process::query()->find($processId) : null;
        if (! $process instanceof Process || $process->owner_type !== Node::class || $process->owner_id !== $id
            || $process->name !== PlausibleProcess::NAME || $process->runtime !== ProcessRuntime::Docker
            || $process->desired_state !== DesiredProcessState::Running) {
            $this->refuse();
        }
        $environment = ValidatedData::stringMap($process->runtime_config['environment'] ?? []);
        ksort($environment);
        $digest = hash('sha256', json_encode($environment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $expected = data_get($operation->state, 'runtime_plan.environment_sha256');
        if (! is_string($expected) || ! hash_equals($expected, $digest)) {
            $this->refuse();
        }

        return new OperationSecretValue($environment);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('analytics.runtime_changed', 'The admitted analytics runtime is no longer current.', 409);
    }
}
