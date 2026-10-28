<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Operation;

final readonly class MetricsResetCompletion implements OperationCompletion
{
    public function __construct(private SettingRepository $settings) {}
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) { return; }
        $scope = new SettingScope(SettingScopeType::Node, $operation->node_id);
        $pending = $this->settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
        if (! is_string($pending) || ! hash_equals($operation->input['pending_sha256'], hash('sha256', $pending))) { throw new ResourceOperationException('operation.completion_unproven', 'The verified credential is no longer pending.', 409); }
        $this->settings->put($scope, NativeMetricsCredentialManager::ActivePasswordKey, $pending, SettingValueProtection::Secret);
        $this->settings->delete($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    }
}
