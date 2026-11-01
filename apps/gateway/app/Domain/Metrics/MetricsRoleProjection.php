<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\NodeRolePublicationState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class MetricsRoleProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $secrets = [];
        if ($operation->input['enabled']) {
            $password = app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, ValidatedData::integer($operation->node_id)), NativeMetricsCredentialManager::ActivePasswordKey);
            if (! is_string($password) || $password === '') {
                throw new ResourceOperationException('metrics.credentials_missing', 'The admitted Grafana credential is missing.', 409);
            }
            $secrets['password'] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'metrics-role-operation-'.$operation->id, $password);
        }

        return ['intent' => $operation->input, 'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if (! in_array($data['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Metrics projects terminal native results only.', 422);
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $operation->result ?? []);
            }
            $intent = $operation->input;
            $assignment = NodeRole::query()->whereKey($intent['assignment_id'])->where('node_id', $operation->node_id)->where('role', RoleName::Metrics)->firstOrFail();
            $success = $data['status'] === 'succeeded';
            if ($success) {
                $certificates = ValidatedData::object($intent['certificates']);
                foreach (RouteExecutionProof::objects($certificates['leaves']) as $leaf) {
                    app(NodeRolePublicationState::class)->record($certificates, 'certificates_published', ValidatedData::integer($leaf['node_id']));
                }
                foreach (RouteExecutionProof::objects($certificates['withdrawals']) as $leaf) {
                    app(NodeRolePublicationState::class)->record($certificates, 'withdrawal_started', ValidatedData::integer($leaf['node_id']));
                }
                if ($intent['enabled']) {
                    $assignment->markConvergenceActive();
                } else {
                    if ($intent['purge_data']) {
                        app(MetricsCredentialManager::class)->purge($assignment->node);
                    }
                    $assignment->delete();
                }
            } else {
                $assignment->update(['status' => LifecycleStatus::Failed, 'failed_step' => $intent['enabled'] ? 'converge:operation' : 'remove:operation', 'error_code' => $data['error_code'] ?? 'operation.native_failed']);
            }

            return app(DirectOperation::class)->project($operation, $data, $success ? ['node_id' => $operation->node_id, 'ready' => true, 'status' => $intent['enabled'] ? 'active' : 'removed', 'publication_cleanup' => $intent['enabled'] ? null : 'cleaned'] : ['error_code' => $data['error_code'] ?? 'operation.native_failed']);
        });
    }
}
