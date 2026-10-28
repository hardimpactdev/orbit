<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\InstanceSmokeBlockData;
use App\Data\Instances\InstanceSmokeBlockReceiptData;
use App\Data\Instances\InstanceSmokeFailureData;
use App\Data\Instances\InstanceSmokeRollbackData;
use App\Data\Instances\InstanceSmokeRollbackDeploymentData;
use App\Data\Instances\InstanceSmokeRollbackReceiptData;
use App\Data\Instances\InstanceSmokeSelectionData;
use App\Data\Operations\OperationData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class InstanceSmokeRecoveryAction
{
    public function __construct(private InstanceEnvironmentOperationLock $lock, private ReadInstanceSmokeReceiptAction $smokes,
        private ReadInstanceSmokeContextAction $context, private ProductionDeployment $deployment,
        private StartOperationAction $operations, private RollbackInstanceAction $rollback) {}

    public function record(Instance $instance, Node $caller, InstanceSmokeFailureData $failure, string $key, string $fingerprint): InstanceSmokeBlockData
    {
        return $this->lock->run([$instance->id], fn (): InstanceSmokeBlockData => DB::transaction(function () use ($instance, $caller, $failure, $key, $fingerprint): InstanceSmokeBlockData {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $receipt = $this->failureReceipt($instance, $caller, $key);
            if ($receipt->block !== null) {
                if ((AutomaticDeployState::of($instance)->smokeFailure($key)['fingerprint'] ?? null) !== $fingerprint) {
                    throw new ResourceOperationException('operation.idempotency_conflict', 'The failure key has another body.', 409);
                }

                return $receipt->block;
            }
            if ($key !== 'smoke-failure.'.$instance->id.'.'.$failure->deploymentId) {
                throw new ResourceOperationException('instance.smoke_failure_invalid', 'The failure key does not match the deployment.');
            }
            $this->proof($instance, $caller, $failure->selection(), $failure->smokeRequestKey, false);
            $state = AutomaticDeployState::of($instance);
            $state->recordSmokeFailure($key, $caller->id, $fingerprint, $failure);
            $state->save($instance);

            return new InstanceSmokeBlockData($key, $instance->id, $failure);
        }));
    }

    public function failureReceipt(Instance $instance, Node $caller, string $key): InstanceSmokeBlockReceiptData
    {
        $owner = preg_match('/\Asmoke-failure\.([1-9][0-9]*)\.[1-9][0-9]*\z/D', $key, $match) === 1
            ? Instance::query()->find($match[1]) : null;
        $entry = $owner === null ? null : AutomaticDeployState::of($owner)->smokeFailure($key);
        if (! is_array($entry)) {
            return new InstanceSmokeBlockReceiptData($key, false, null);
        }
        if ($owner->id !== $instance->id || $entry['caller_node_id'] !== $caller->id) {
            throw new ResourceOperationException('operation.idempotency_conflict', 'The failure key belongs to another caller or Instance.', 409);
        }

        return new InstanceSmokeBlockReceiptData($key, true,
            new InstanceSmokeBlockData($key, $instance->id, InstanceSmokeFailureData::from($entry['failure'])));
    }

    public function start(Instance $instance, Node $caller, InstanceSmokeRollbackData $data, string $key, string $requestId): Operation
    {
        if ($key !== $data->failureKey.'.rollback') {
            throw new ResourceOperationException('instance.smoke_rollback_invalid', 'The rollback key does not match the failure.');
        }

        return $this->lock->run([$instance->id], function () use ($instance, $caller, $data, $requestId): Operation {
            $this->guard($instance, $caller, $data);
            $this->rollback->plan($instance);

            return $this->operations->execute(OperationKinds::InstanceRollback, 'instance', $instance->id, $instance->node_id,
                [...$data->toArray(), 'caller_node_id' => $caller->id], 'swamp:smoke', $requestId,
                supersedes: [OperationKinds::InstanceDeploy]);
        });
    }

    public function guard(Instance $instance, Node $caller, InstanceSmokeRollbackData $data, bool $activated = false): void
    {
        $instance->refresh();
        $failure = $this->failureReceipt($instance, $caller, $data->failureKey)->block?->failure;
        if ($failure === null || $failure->selection()->toArray() !== $data->expected->toArray()
            || ! in_array($failure->commit, AutomaticDeployState::of($instance)->blocked(), true)) {
            throw new ResourceOperationException('instance.smoke_rollback_invalid', 'Rollback requires the recorded failure and permanent block.');
        }
        if ($data->target->commit === $failure->commit || $data->target->release === $failure->release
            || $data->target->deploymentId >= $failure->deploymentId) {
            throw new ResourceOperationException('instance.smoke_rollback_invalid', 'The target must be a distinct prior deployment.');
        }
        $expected = $activated ? $data->target : $data->expected;
        if ($activated) {
            $actual = $this->deployment->selected($instance);
            $selected = $actual === null ? null : new InstanceSmokeSelectionData($expected->deploymentId, $actual->commit, $actual->name);
        } else {
            $selected = $this->context->execute($instance)->selected;
        }
        if ($selected === null || $selected->commit !== $expected->commit || $selected->release !== $expected->release
            || (! $activated && $selected->deploymentId !== $expected->deploymentId)) {
            throw new ResourceOperationException('instance.smoke_selection_changed', 'The selected deployment changed.', 409);
        }
        $this->proof($instance, $caller, $data->target, $data->targetSmokeRequestKey, true);
        if (! in_array($data->target->release, $this->deployment->releases($instance)->releases, true)) {
            throw new ResourceOperationException('instance.smoke_rollback_target_missing', 'The smoke-passed target is not retained.', 409);
        }
        $target = $this->deployment->retained($instance, $data->target->release);
        if ($target->commit !== $data->target->commit || $target->name !== $data->target->release) {
            throw new ResourceOperationException('instance.smoke_rollback_target_missing', 'The retained target identity changed.', 409);
        }
    }

    public function rollbackReceipt(Instance $instance, Node $caller, string $key): InstanceSmokeRollbackReceiptData
    {
        $operation = Operation::query()->where('kind', OperationKinds::InstanceRollback)->where('idempotency_key', $key)->first();
        if ($operation === null) {
            return new InstanceSmokeRollbackReceiptData($key, false, null, null);
        }
        if ($operation->target_type !== 'instance' || $operation->target_id !== $instance->id
            || $operation->callerNodeId() !== $caller->id || ! isset($operation->input['failure_key'])) {
            throw new ResourceOperationException('operation.idempotency_conflict', 'The rollback key belongs to another admission.', 409);
        }
        $deploymentId = $operation->state['deployment_id'] ?? null;
        $row = is_int($deploymentId) ? InstanceDeployment::query()->where('instance_id', $instance->id)->find($deploymentId) : null;
        $deployment = $row !== null && is_string($row->commit) && is_string($row->release)
            ? new InstanceSmokeRollbackDeploymentData($row->id, $row->commit, $row->release, $row->status) : null;

        return new InstanceSmokeRollbackReceiptData($key, true, OperationData::fromModel($operation), $deployment);
    }

    private function proof(Instance $instance, Node $caller, InstanceSmokeSelectionData $selection, string $key, bool $passed): void
    {
        $code = $passed ? 'instance.smoke_rollback_invalid' : 'instance.smoke_failure_invalid';
        try {
            $receipt = $this->smokes->execute($instance, $caller, $key);
        } catch (ResourceOperationException) {
            throw new ResourceOperationException($code, 'The smoke receipt does not belong to this caller and Instance.');
        }
        $op = $receipt->operation;
        $smoke = $receipt->smoke;
        $ownership = $this->context->ownership($instance);
        $repository = GitHubRepository::fromOrigin($ownership->repositoryUrl);
        $record = $op === null ? null : Operation::query()->find($op->id);
        $workflow = $record?->state['workflow'] ?? null;
        if ($op === null || $smoke === null || $op->status !== 'succeeded'
            || $smoke->state !== ($passed ? 'passed' : 'failed')
            || $smoke->deploymentId !== $selection->deploymentId || $smoke->commit !== $selection->commit || $smoke->release !== $selection->release
            || $smoke->tests <= $smoke->skipped || ($passed ? $smoke->failures + $smoke->errors + $smoke->skipped !== 0
                || $smoke->conclusion !== 'success' : $smoke->failures + $smoke->errors < 1)
            || ($smoke->runId ?? 0) < 1 || ($smoke->runAttempt ?? 0) < 1
            || preg_match('/\A[0-9a-f]{40}\z/D', $smoke->workflowSha ?? '') !== 1 || $smoke->artifacts === []
            || ! is_array($workflow) || ($workflow['sha'] ?? null) !== $smoke->workflowSha
            || ! is_int($workflow['id'] ?? null) || $workflow['id'] < 1 || ! is_string($record?->state['intent'] ?? null)
            || $repository === null || $smoke->runUrl !== 'https://github.com/'.$repository->owner.'/'.$repository->name.'/actions/runs/'.$smoke->runId
            || ! $ownership->production || ! in_array($smoke->baseUrl, $ownership->baseUrls, true)
            || ($op->input['repository_url'] ?? null) !== $ownership->repositoryUrl
            || ! $instance->routes()->whereKey($op->input['route_id'] ?? 0)->where('domain', substr($smoke->baseUrl, 8))
                ->where('project_id', $instance->project_id)->where('status', 'active')->where('publication', 'public')->exists()) {
            throw new ResourceOperationException($code, 'A verified executed smoke with matching ownership is required.');
        }
        foreach (['deployment_id', 'commit', 'release', 'base_url', 'workflow', 'ref'] as $field) {
            if (($op->input[$field] ?? null) !== $smoke->toArray()[$field]) {
                throw new ResourceOperationException($code, 'Smoke input and verified target evidence differ.');
            }
        }
    }
}
