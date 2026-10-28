<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\InstanceSmokeControlData;
use App\Data\Instances\InstanceSmokeControlEntryData;
use App\Data\Instances\InstanceSmokeControlLookupData;
use App\Data\Instances\InstanceSmokeControlReceiptData;
use App\Data\Instances\InstanceSmokeControlStateData;
use App\Data\Instances\InstanceSmokeHoldStateData;
use App\Data\Instances\InstanceSmokeSelectionData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationKinds;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Releases\ReleaseIncident;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemFingerprint;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class InstanceSmokeControlAction
{
    public function __construct(private InstanceEnvironmentOperationLock $lock, private ReadInstanceSmokeContextAction $context,
        private InstanceSmokeRecoveryAction $recovery, private StartOperationAction $operations, private ReleaseAlertNotifier $alerts) {}

    public function execute(Instance $instance, Node $caller, InstanceSmokeControlData $data, string $key): InstanceSmokeControlReceiptData
    {
        return $this->lock->run([$instance->id], function () use ($instance, $caller, $data, $key): InstanceSmokeControlReceiptData {
            $instance->refresh();
            $control = $this->state($instance);
            $fingerprint = hash('sha256', json_encode([$caller->id, $data->toArray()], JSON_THROW_ON_ERROR));
            $entry = $control->receipts[$key] ?? null;
            if ($entry !== null) {
                $this->check($entry->caller === $caller->id && $entry->fingerprint === $fingerprint, 'operation.idempotency_conflict');

                if ($entry->receipt->reason !== 'release.alert_delivery_failed') {
                    return $entry->receipt;
                }
            }
            $ownership = $this->context->ownership($instance);
            $this->check($ownership->production && $ownership->baseUrls !== [] && GitHubRepository::fromOrigin($ownership->repositoryUrl) !== null);
            $hold = $control->hold ?? null;
            $resumed = false;
            $reason = null;
            if ($data->action === 'hold') {
                $this->selected($instance, $data->expected);
                $this->check($hold === null, 'instance.smoke_held');
                $state = AutomaticDeployState::of($instance)->toArray();
                $hold = new InstanceSmokeHoldStateData($data->holdKey, $caller->id, $data->expected,
                    is_int($state['operator_version'] ?? null) ? $state['operator_version'] : 0,
                    $instance->auto_deploy_resumed_after,
                    $instance->auto_deploy && new AutomaticDeployHistory($instance)->pausedBy() === null,
                    InstanceDeployment::query()->where('instance_id', $instance->id)->latest('id')->first()->id ?? 0,
                    Operation::query()->latest('id')->first()->id ?? 0);
                $control->hold = $hold;
                $control->fingerprints = [];
                $control->alerts = [];
            } else {
                $this->check($hold !== null && $hold->key === $data->holdKey && $hold->caller === $caller->id);
                if ($data->action === 'alert') {
                    $this->check($data->expected->toArray() === $hold->expected->toArray());
                    $repository = GitHubRepository::fromOrigin($instance->project->repository_url);
                    assert($repository !== null);
                    $receipt = $this->alerts->alert(new ReleaseAlert(ReleaseAlertKind::InstanceSmokeHeld,
                        new ReleaseAlertSubject('instance:'.$instance->id, $repository->owner.'/'.$repository->name, $data->expected->commit),
                        'Production smoke needs assistance. Automatic deploys are held; the release remains live until verified evidence is available.',
                        new ReleaseIncident('instance:'.$instance->id, $data->holdKey, 'smoke')));
                    $control->alerts[] = $receipt->toArray();
                    if ($receipt->problem->fingerprint !== null) {
                        $control->fingerprints[] = $receipt->problem->fingerprint;
                    }
                    $reason = $receipt->problem->failedStep() || $receipt->webhook->failedStep() ? 'release.alert_delivery_failed' : null;
                } elseif ($data->action === 'release') {
                    $this->selected($instance, $data->expected);
                    $this->check($data->smokeRequestKey !== null);
                    $this->recovery->proof($instance, $caller, $data->expected, $data->smokeRequestKey, true);
                    $smoke = Operation::query()->where('kind', OperationKinds::InstanceSmoke)->where('idempotency_key', $data->smokeRequestKey)->sole();
                    $this->check($smoke->id > $hold->smokeAfter);
                    $owned = $this->ownedRows($instance, $caller, $data);
                    $state = AutomaticDeployState::of($instance)->toArray();
                    $resumed = $hold->resume && $instance->auto_deploy && ($state['operator_version'] ?? 0) === $hold->operatorVersion
                        && $instance->auto_deploy_resumed_after === $hold->cursor
                        && ! InstanceDeployment::query()->where('instance_id', $instance->id)->where('id', '>', $hold->lastId)
                            ->whereNotIn('id', $owned)->where(fn ($q) => $q->where('kind', 'rollback')->orWhereNotIn('triggered_by', AutomaticDeployHistory::Triggers)->orWhereNull('triggered_by'))->exists();
                    if ($resumed) {
                        $instance->auto_deploy_resumed_after = InstanceDeployment::query()->where('instance_id', $instance->id)->latest('id')->first()->id ?? 0;
                    } else {
                        $reason = 'instance.smoke_operator_pause_preserved';
                    }
                    $control->hold = null;
                } else {
                    throw new ResourceOperationException('instance.smoke_control_invalid', 'Use the redeploy endpoint.', 422);
                }
            }
            $result = new InstanceSmokeControlReceiptData($key, $instance->id, $data->action, $data->holdKey, $control->hold !== null, $resumed, $reason);
            $control->receipts[$key] = new InstanceSmokeControlEntryData($caller->id, $fingerprint, $result);
            DB::transaction(function () use ($instance, $control): void {
                $instance->save();
                $this->save($instance, $control);
                if ($control->hold === null) {
                    foreach ($control->fingerprints as $problemFingerprint) {
                        $problem = ProblemFingerprint::query()->where('fingerprint', $problemFingerprint)->first();
                        if ($problem !== null) {
                            app(ProblemRecovery::class)->resolve($problem, 'verified_production_smoke');
                        }
                    }
                }
            });

            return $result;
        });
    }

    public function receipt(Instance $instance, Node $caller, string $key): InstanceSmokeControlLookupData
    {
        $entry = $this->state($instance)->receipts[$key] ?? null;
        $this->check($entry === null || $entry->caller === $caller->id, 'operation.idempotency_conflict');

        return new InstanceSmokeControlLookupData($key, $entry !== null, $entry?->receipt);
    }

    public function redeploy(Instance $instance, Node $caller, InstanceSmokeControlData $data, string $requestId): Operation
    {
        return $this->lock->run([$instance->id], function () use ($instance, $caller, $data, $requestId): Operation {
            $this->check($data->action === 'redeploy');
            $input = [...$data->toArray(), 'smoke_hold_key' => $data->holdKey, 'commit' => $data->expected->commit, 'caller_node_id' => $caller->id];
            $operation = new Operation(['input' => $input]);
            $this->guardRedeploy($instance, $operation);

            return $this->operations->execute(OperationKinds::InstanceDeploy, 'instance', $instance->id, $instance->node_id, $input, 'swamp:smoke', $requestId);
        });
    }

    public function guardRedeploy(Instance $instance, Operation $operation): void
    {
        $instance->refresh();
        $data = InstanceSmokeControlData::from($operation->input);
        $caller = Node::query()->findOrFail($operation->callerNodeId());
        $hold = $this->state($instance)->hold ?? null;
        $this->check($hold !== null && $hold->key === $data->holdKey && $hold->caller === $caller->id);
        $activated = DeployInstanceAction::storedRelease((is_array($operation->state['steps'] ?? null) ? $operation->state['steps'] : [])['activation'] ?? null);
        if ($activated === null) {
            $this->selected($instance, $data->expected);
        } else {
            $selected = app(ProductionDeployment::class)->selected($instance);
            $this->check($selected?->name === $activated->name && $selected->commit === $data->expected->commit);
        }
        $this->ownedRows($instance, $caller, $data, true);
    }

    /** @return list<int> */
    private function ownedRows(Instance $instance, Node $caller, InstanceSmokeControlData $data, bool $requireRollback = false): array
    {
        if ($data->rollbackRequestKey === null) {
            $this->check(! $requireRollback);

            return [];
        }
        $rollback = $this->recovery->rollbackReceipt($instance, $caller, $data->rollbackRequestKey);
        $op = $rollback->operation;
        $row = $rollback->deployment;
        $hold = $this->state($instance)->hold;
        $this->check($hold !== null && $op !== null && $op->status === 'succeeded' && $row?->status === 'succeeded'
            && $row->commit === $data->expected->commit && ($op->input['expected'] ?? null) === $hold->expected->toArray());
        if ($requireRollback) {
            $this->check(($op->input['recovery_redeploy_required'] ?? false) === true && $row->deploymentId === $data->expected->deploymentId);
        }
        $ids = [$row->deploymentId];
        if ($requireRollback) {
            return $ids;
        }
        foreach (Operation::query()->where('kind', OperationKinds::InstanceDeploy)->where('target_id', $instance->id)
            ->where('input->smoke_hold_key', $data->holdKey)->get() as $redeploy) {
            $this->check($redeploy->callerNodeId() === $caller->id && $redeploy->status->value === 'succeeded');
            $id = $redeploy->state['deployment_id'] ?? null;
            if (is_int($id)) {
                $ids[] = $id;
            }
        }
        if ($op->input['recovery_redeploy_required'] ?? false) {
            $this->check(count($ids) > 1);
        }

        return $ids;
    }

    private function selected(Instance $instance, InstanceSmokeSelectionData $expected): void
    {
        $this->check($this->context->execute($instance)->selected?->toArray() === $expected->toArray(), 'instance.smoke_selection_changed');
    }

    private function state(Instance $instance): InstanceSmokeControlStateData
    {
        return InstanceSmokeControlStateData::from(ValidatedData::object(AutomaticDeployState::of($instance)->toArray()['smoke_control'] ?? []));
    }

    private function save(Instance $instance, InstanceSmokeControlStateData $control): void
    {
        $state = AutomaticDeployState::of($instance);
        $state->smokeControl($control->toArray());
        $state->save($instance);
    }

    /** @phpstan-assert true $condition */
    private function check(bool $condition, string $code = 'instance.smoke_control_invalid'): void
    {
        if (! $condition) {
            throw new ResourceOperationException($code, 'Smoke control identity or recovery proof changed.', 409);
        }
    }
}
