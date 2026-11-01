<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\NodeEnrollmentCandidate;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use SensitiveParameter;

/** Existing Grafana material, delivered only to its admitted Metrics owner. */
final readonly class MetricsFleetRuntimeMaterial implements OperationSecretSource
{
    public function __construct(private MetricsCredentialManager $credentials, private MetricsFleetCapture $capture, private SettingRepository $settings) {}

    public function name(): string
    {
        return 'metrics.fleet_runtime';
    }

    public function targetTypes(): array
    {
        return ['metrics', 'node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if ($this->parentPath($operation) !== null) {
            $plan = Arr::get($operation->input, $this->parentPath($operation));
            $id = is_array($plan) ? ($plan['metrics_node_id'] ?? null) : null;
            $bindings = $operation->input['private_bindings'] ?? null;
            if ($operation->target_type !== 'node' || ! is_int($id) || $id < 1
                || ! Node::query()->whereKey($operation->target_id)->exists()
                || ! is_array($bindings) || ($bindings['metrics_runtime'] ?? null) !== ['owner_node_id' => $id, 'read_node_ids' => [$id], 'write_node_ids' => []]) {
                return null;
            }

            return app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'metrics_runtime') === $id ? $id : null;
        }

        return $operation->target_type === 'metrics' && in_array($operation->kind, ['metrics.reconcile', 'metrics.exporter_enable', 'metrics.exporter_disable'], true) && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $path = $this->parentPath($operation);
        $plan = ValidatedData::object(Arr::get($operation->input, $path ?? 'metrics_fleet', []));
        MetricsFleetCapture::assertDigest($plan);
        if ($key !== '' || $parameter !== ($path === null ? 'fleet_runtime' : 'metrics_runtime') || $operation->status->terminal()
            || ! is_string($operation->state['swamp_run_id'] ?? null) || $id !== $this->ownerId($operation) || $id !== ($plan['metrics_node_id'] ?? null)
            || ($path === null && array_any(MetricsFleetCapture::members($plan), static fn (array $node): bool => $node['worker'] !== true))
            || ! NodeRole::query()->whereKey($plan['assignment_id'])->where('node_id', $id)->where('role', RoleName::Metrics)->whereIn('status', $path === null ? [LifecycleStatus::Active] : [LifecycleStatus::Provisioning, LifecycleStatus::Active, LifecycleStatus::Removing])->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The private Metrics runtime belongs to its active native root.', 422);
        }
        $node = Node::query()->findOrFail($id);
        if ($node->status !== LifecycleStatus::Active || $node->wireguard_ip !== ($plan['metrics_address'] ?? null)) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The Metrics owner changed before runtime commit.', 409);
        }
        if ($path === null && ! hash_equals(ValidatedData::string($plan['digest']), ValidatedData::string($this->capture->capture()['digest']))) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The desired fleet changed before runtime commit.', 409);
        }
        if ($path !== null) {
            return $this->resolveFleetMaterial($plan, $id);
        }

        return $this->material($plan, $this->credentials->passwordForConvergence($node));
    }

    /** Existing type only, after the owning parent admits this exact public fleet plan.
     * This read-only lower grants no Operation, transport or credential-creation authority.
     *
     * @param  array<string,mixed>  $plan
     */
    public function resolveFleetMaterial(array $plan, int $ownerNodeId, ?NodeEnrollmentCandidate $candidate = null): OperationSecretValue
    {
        MetricsFleetCapture::assertDigest($plan);
        if ($ownerNodeId !== $plan['metrics_node_id'] || ($candidate !== null && $candidate->nodeId !== $ownerNodeId)
            || ! NodeRole::query()->whereKey($plan['assignment_id'])->where('node_id', $ownerNodeId)->where('role', RoleName::Metrics)
                ->whereIn('status', [LifecycleStatus::Provisioning, LifecycleStatus::Active, LifecycleStatus::Removing])->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The private fleet material requires its actual admitted Metrics assignment.', 422);
        }
        $node = Node::query()->findOrFail($ownerNodeId);
        $address = $candidate?->wireguardIp ?? $node->wireguard_ip;
        $eligible = $node->status === LifecycleStatus::Active || ($candidate !== null
            && in_array($node->status, [LifecycleStatus::Provisioning, LifecycleStatus::Failed], true));
        if (! $eligible || $address !== $plan['metrics_address'] || ($candidate !== null && $candidate->platform !== 'linux')) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The admitted Metrics owner changed before private capture.', 409);
        }
        $password = $this->settings->get(new SettingScope(SettingScopeType::Node, $ownerNodeId), NativeMetricsCredentialManager::ActivePasswordKey);

        return $this->material($plan, $password);
    }

    /** @param array<string,mixed> $plan */
    private function material(array $plan, #[SensitiveParameter] ?string $password): OperationSecretValue
    {
        if (! is_string($password) || $password === '') {
            throw new ResourceOperationException('metrics.credentials_missing', 'The recorded Grafana credential is unavailable.', 409);
        }
        $files = ValidatedData::stringMap($plan['runtime_files']);
        $files['/etc/orbit/metrics/grafana/admin-password'] = $password;

        return new OperationSecretValue(['runtime' => ['operation' => 'converge', 'assignment' => $plan['assignment_id'], 'configuration' => $files],
            'verify' => ['operation' => 'verify', 'password' => $password]], [$password]);
    }

    /** Fixed owning-parent capture paths; never a caller-selected source or field. */
    private function parentPath(Operation $operation): ?string
    {
        return match ($operation->kind) {
            'node.role_add', 'node.role_converge' => '_role.metrics_fleet',
            'node.role_remove' => '_role_remove.metrics_fleet',
            'node.role_relocate' => '_relocate.metrics_fleet',
            'node.remove' => ($operation->result['restoring'] ?? false) === true ? '_removal.restore_metrics_fleet' : '_removal.metrics_fleet',
            default => null,
        };
    }
}
