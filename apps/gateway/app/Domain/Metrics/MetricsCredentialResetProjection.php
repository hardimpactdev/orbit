<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class MetricsCredentialResetProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $settings = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Node, ValidatedData::integer($operation->node_id));
        $active = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
        $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
        if (! is_string($active) || $active === '' || ! is_string($pending) || ! hash_equals(ValidatedData::string($operation->input['pending_fingerprint']), hash('sha256', $pending))) {
            throw new ResourceOperationException('metrics.credentials_missing', 'The admitted Grafana credential copy is unavailable.', 409);
        }

        return ['intent' => $operation->input, 'execution_secrets' => ['credentials' => app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'metrics-credentials-operation-'.$operation->id,
            json_encode(['active_password' => $active, 'pending_password' => $pending], JSON_THROW_ON_ERROR))]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if (! in_array($data['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Credentials project terminal native results only.', 422);
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            Node::query()->whereKey($operation->node_id)->lockForUpdate()->firstOrFail();
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = app(DirectOperation::class)->project($operation, $data,
                $data['status'] === 'succeeded' ? ['reset' => true] : ['error_code' => $data['error_code'] ?? 'operation.native_failed']);
            if (! $terminal && $data['status'] === 'succeeded') {
                $settings = app(SettingRepository::class);
                $scope = new SettingScope(SettingScopeType::Node, ValidatedData::integer($operation->node_id));
                $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
                if (is_string($pending) && hash_equals(ValidatedData::string($operation->input['pending_fingerprint']), hash('sha256', $pending))) {
                    $settings->put($scope, NativeMetricsCredentialManager::ActivePasswordKey, $pending, SettingValueProtection::Secret);
                    $settings->delete($scope, NativeMetricsCredentialManager::PendingPasswordKey);
                }
            }

            return $projected;
        });
    }
}
