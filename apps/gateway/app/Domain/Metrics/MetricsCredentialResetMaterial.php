<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Str;

/** Existing encrypted Grafana credential type, owned by one admitted reset revision. */
final readonly class MetricsCredentialResetMaterial implements OperationSecretSource
{
    public const string RevisionKey = 'metrics.grafana.reset_revision';

    public function __construct(private SettingRepository $settings) {}

    public function name(): string
    {
        return 'metrics.credentials_reset';
    }

    public function targetTypes(): array
    {
        return ['metrics'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'metrics.credentials_reset' && $operation->target_type === 'metrics'
            && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function prepare(Node $node): string
    {
        $scope = new SettingScope(SettingScopeType::Node, $node->id);
        $this->password($scope, NativeMetricsCredentialManager::ActivePasswordKey);
        if ($this->settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === null) {
            $this->settings->put($scope, NativeMetricsCredentialManager::PendingPasswordKey, Str::random(32), SettingValueProtection::Secret);
        }
        $revision = (string) Str::uuid();
        $this->settings->put($scope, self::RevisionKey, $revision);

        return $revision;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($key !== '' || $parameter !== 'credential' || $id !== $this->ownerId($operation) || $operation->status->terminal()) {
            throw $this->forbidden();
        }
        $scope = $this->scope($operation);
        $node = Node::query()->find($id);
        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active
            || $node->wireguard_ip !== ($operation->input['address'] ?? null)
            || ! NodeRole::query()->whereKey($operation->input['assignment_id'] ?? null)->where('node_id', $id)
                ->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->forbidden();
        }
        $active = $this->password($scope, NativeMetricsCredentialManager::ActivePasswordKey);
        $pending = $this->password($scope, NativeMetricsCredentialManager::PendingPasswordKey);

        return new OperationSecretValue(['active_password' => $active, 'pending_password' => $pending], [$active, $pending]);
    }

    /** Called only in the terminal projection transaction, after the native result is recorded. */
    public function promote(Operation $operation): void
    {
        $scope = $this->scope($operation);
        $pending = $this->password($scope, NativeMetricsCredentialManager::PendingPasswordKey);
        $this->settings->put($scope, NativeMetricsCredentialManager::ActivePasswordKey, $pending, SettingValueProtection::Secret);
        $this->settings->delete($scope, NativeMetricsCredentialManager::PendingPasswordKey);
        $this->settings->delete($scope, self::RevisionKey);
    }

    private function scope(Operation $operation): SettingScope
    {
        $id = $this->ownerId($operation);
        if ($id === null || ! DirectOperation::owns($operation) || ! is_string($operation->state['swamp_run_id'] ?? null)) {
            throw $this->forbidden();
        }
        $scope = new SettingScope(SettingScopeType::Node, $id);
        if ($this->settings->get($scope, self::RevisionKey) !== ($operation->input['reset_revision'] ?? null)) {
            throw $this->forbidden();
        }

        return $scope;
    }

    private function password(SettingScope $scope, string $key): string
    {
        $value = $this->settings->get($scope, $key);
        if (! is_string($value) || $value === '') {
            throw new ResourceOperationException('metrics.credentials_missing', 'The recorded Grafana credential is unavailable.', 409);
        }

        return $value;
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The credential belongs to its admitted Metrics reset.', 422);
    }
}
