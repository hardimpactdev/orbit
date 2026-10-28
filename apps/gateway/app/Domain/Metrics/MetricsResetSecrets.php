<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class MetricsResetSecrets implements OperationSecretSource
{
    public function __construct(private SettingRepository $settings) {}

    public function name(): string
    {
        return 'metrics.reset';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['metrics_credentials'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'metrics.credentials_reset' ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'action' || ! in_array($key, ['credential-apply', 'credential-verify'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The pending credential has another owner.', 422);
        }
        $scope = new SettingScope(SettingScopeType::Node, $id);
        $active = $this->settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
        $pending = $this->settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
        // Completion may already have promoted the password before a lost reply is replayed.
        if ($pending === null && is_string($active) && hash_equals(ValidatedData::string($operation->input['pending_sha256']), hash('sha256', $active))) {
            $pending = $active;
        }
        if (! is_string($pending) || ! is_string($active) || ! hash_equals(ValidatedData::string($operation->input['pending_sha256']), hash('sha256', $pending))) {
            throw new ResourceOperationException('operation.secret_unavailable', 'The admitted pending credential is unavailable.', 409);
        }

        return new OperationSecretValue($key === 'credential-apply' ? ['operation' => 'apply', 'active_password' => $active, 'pending_password' => $pending] : ['operation' => 'verify', 'password' => $pending]);
    }
}
