<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Standalone root's existing Metrics credential; foreign role roots stay refused. */
final readonly class MetricsFleetRuntimeMaterial implements OperationSecretSource
{
    public function __construct(private MetricsCredentialManager $credentials, private MetricsFleetCapture $capture) {}

    public function name(): string
    {
        return 'metrics.fleet_runtime';
    }

    public function targetTypes(): array
    {
        return ['metrics'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->target_type === 'metrics' && $operation->kind === 'metrics.reconcile' && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $plan = ValidatedData::object($operation->input['metrics_fleet'] ?? []);
        MetricsFleetCapture::assertDigest($plan);
        if ($key !== '' || $parameter !== 'fleet_runtime' || ! DirectOperation::owns($operation) || $operation->status->terminal()
            || ! is_string($operation->state['swamp_run_id'] ?? null) || $id !== $this->ownerId($operation) || $id !== ($plan['metrics_node_id'] ?? null)
            || array_any(MetricsFleetCapture::members($plan), static fn (array $node): bool => $node['worker'] !== true)
            || ! NodeRole::query()->whereKey($plan['assignment_id'])->where('node_id', $id)->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The private Metrics runtime belongs to its active native root.', 422);
        }
        if (! hash_equals(ValidatedData::string($plan['digest']), ValidatedData::string($this->capture->capture()['digest']))) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The desired fleet changed before runtime commit.', 409);
        }
        $password = $this->credentials->passwordForConvergence(Node::query()->findOrFail($id));
        $files = ValidatedData::stringMap($plan['runtime_files']);
        $files['/etc/orbit/metrics/grafana/admin-password'] = $password;

        return new OperationSecretValue(['runtime' => ['operation' => 'converge', 'assignment' => $plan['assignment_id'], 'configuration' => $files],
            'verify' => ['operation' => 'verify', 'password' => $password]], [$password]);
    }
}
