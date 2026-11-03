<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\WebSocketRuntime;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records the native relocation result. The run owns every Node effect; this writes Gateway state only. */
final readonly class NodeRoleRelocateProjection implements DirectOperationProjection
{
    public const string EndCheck = 'Confirm the move';

    public const string SourceStep = 'Remove from the source';

    public const string RouteStep = 'Route the private domain';

    /** The role's secrets go to the vault as execution copies; the inputs carry only their names. */
    public function input(Operation $operation): array
    {
        $intent = ValidatedData::object($operation->input['intent'] ?? []);
        $targetId = ValidatedData::integer($intent['target_node_id']);
        $target = new SettingScope(SettingScopeType::Node, $targetId);
        $settings = app(SettingRepository::class);
        $steps = app(SwampCommandStep::class);
        $secrets = [];
        if ($intent['role'] === RoleName::Metrics->value) {
            $password = $settings->get($target, NativeMetricsCredentialManager::ActivePasswordKey);
            if (! is_string($password) || $password === '') {
                throw new ResourceOperationException('metrics.credentials_missing', 'The admitted Grafana credential is missing.', 409);
            }
            $secrets['password'] = $steps->executionCopy('orbit-control-inputs', 'metrics-role-operation-'.$operation->id, $password);
        }
        if ($intent['role'] === RoleName::WebSocket->value) {
            $appKey = $settings->get($target, WebSocketFootprint::SettingKeyAppKeyLaravel);
            $registry = WebSocketRuntime::query()->where('node_id', $targetId)->where('slot', 'default')->where('required', true)->first();
            if (! is_string($appKey) || $appKey === '' || ! $registry instanceof WebSocketRuntime) {
                throw new ResourceOperationException('websocket.credentials_missing', 'The admitted Reverb credentials are missing.', 409);
            }
            $environment = app(WebSocketEnvironmentRenderer::class)->render(new WebSocketServerCredentials($appKey, $registry->id, $registry->registry_token),
                ValidatedData::integer(ValidatedData::object($intent['websocket'])['port']));
            $secrets['websocket_env'] = $steps->executionCopy('orbit-control-inputs', 'node-role-relocate-operation-'.$operation->id, $environment);
        }

        return ['intent' => $intent, 'execution_secrets' => $secrets];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? ''));
        if (! $status->terminal()) {
            throw new ResourceOperationException('operation.result_invalid', 'Role relocation projects terminal native results only.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $intent = ValidatedData::object($operation->input['intent']);
            $raw = ValidatedData::object($data['result'] ?? []);
            $succeeded = $status === OperationStatus::Succeeded && NativeRunResult::stepStatus($raw, self::EndCheck) === 'succeeded';
            $summary = ValidatedData::object($raw['summary'] ?? ['failed_steps' => []]);
            $failedSteps = ValidatedData::stringList($summary['failed_steps'] ?? []);
            $failedStep = $failedSteps[0] ?? 'operation';
            $errorCode = is_string($data['error_code'] ?? null) ? $data['error_code'] : 'operation.native_failed';
            $followUps = [];
            if ($this->routeFailed($raw)) {
                $followUps[] = 'The Node does not route the private domain to Orbit VPN DNS yet. Run `orbit node:role:add '
                    .ValidatedData::integer($intent['target_node_id']).' gateway --converge`.';
            }
            if (is_string($intent['metrics_converge'] ?? null)) {
                $followUps[] = "Metrics still scrapes the former sites. Run `orbit node:role:add {$intent['metrics_converge']} metrics --converge`.";
            }
            $result = $succeeded
                ? ['node_id' => $intent['target_node_id'], 'source_node_id' => $intent['source_node_id'], 'role' => $intent['role'], 'relocated' => true,
                    ...($followUps === [] ? [] : ['follow_up' => implode(' ', $followUps)])]
                : ['failed_step' => $failedStep, 'error_code' => $errorCode];
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $assignment = NodeRole::query()->whereKey($intent['assignment_id'])->where('node_id', $operation->node_id)
                ->where('role', $intent['role'])->firstOrFail();
            if ($succeeded) {
                $assignment->markConvergenceActive();
                $this->recordPublication($intent);
                NodeRoleRemovalProjection::recordCleanup(['node_id' => $intent['source_node_id'], 'role' => $intent['role']], true);
            } else {
                $assignment->update(['status' => LifecycleStatus::Failed,
                    'failed_step' => ($failedStep === self::SourceStep ? 'remove:' : 'converge:').$failedStep, 'error_code' => $errorCode]);
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id,
                NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }

    /** @param array<string,mixed> $raw */
    private function routeFailed(array $raw): bool
    {
        return array_any(ValidatedData::object($raw['steps'] ?? []), fn ($step, $name) => str_starts_with($name, self::RouteStep) && (ValidatedData::object($step)['status'] ?? null) === 'failed');
    }

    /** The target serves its sites and the source has withdrawn them, so later renders follow the move.
     * @param  array<string,mixed>  $intent
     */
    private function recordPublication(array $intent): void
    {
        $certificates = ValidatedData::object($intent['certificates']);
        $state = app(NodeRolePublicationState::class);
        $target = ValidatedData::integer($intent['target_node_id']);
        $source = ValidatedData::integer($intent['source_node_id']);
        $state->record($certificates, 'certificates_published', $target);
        $state->record($certificates, 'caddy_published', $target);
        $state->record($certificates, 'withdrawal_started', $source);
        $state->record($certificates, 'withdrawal_completed', $source);
    }
}
