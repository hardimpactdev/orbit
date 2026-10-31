<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** The native parent chooses execution order. These bounded callbacks only change domain state. */
final readonly class NativeInstanceTransferProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct, private NativeRouteTransferPlan $routes) {}

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($operation->kind, ['instance.transfer', 'instance.transfer_rollback'], true)
            || ! in_array($callerNodeId, ValidatedData::integerList($operation->input['participant_node_ids'] ?? []), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Node is not an admitted transfer participant.', 403);
        }
    }

    public function input(Operation $operation): array
    {
        return $this->publicInput($operation, ValidatedData::integer($operation->node_id));
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);
        $transfer = InstanceTransfer::query()->findOrFail(ValidatedData::string($operation->input['transfer_id']));
        $plan = ValidatedData::object($operation->input['direct_transfer']);
        $plan['cutover'] = $transfer->cutover_at !== null;
        $plan['completed'] = $transfer->completed_at !== null;

        return [...$this->routes->publicInput($operation, $callerNodeId), 'direct_transfer' => $plan,
            'process_runtime' => app(InstanceTransferProcesses::class)->input($operation, $callerNodeId)];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $transfer = InstanceTransfer::query()->lockForUpdate()->findOrFail(ValidatedData::string($operation->input['transfer_id']));
            $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
            if ($transfer->instance_id !== $instance->id || $operation->node_id !== $transfer->source_node_id) {
                throw $this->conflict();
            }
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['checkpoint', 'error_code', 'recovered']);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $snapshot = ValidatedData::object($operation->input['route_transfer']);
            if (! $operation->status->terminal() && $status === OperationStatus::Running) {
                $checkpoint = $result['checkpoint'] ?? null;
                if ($checkpoint === 'cutover' && $transfer->cutover_at === null) {
                    if ($operation->cancel_requested_at !== null || $instance->node_id !== $transfer->source_node_id
                        || $instance->checkout_path !== $transfer->source_path) {
                        throw $this->conflict();
                    }
                    app(ScheduleTargetUseGuard::class)->assertInstanceStable($instance);
                    app(NativeInstanceTransferIntent::class)->assertSupported($instance, $transfer->sqlite_source_path);
                    app(InstanceTransferProcesses::class)->cutover($instance, $transfer, ValidatedData::object($operation->input['process_installations']));
                    $instance->update(['node_id' => $transfer->destination_node_id, 'name' => $transfer->destination_name,
                        'checkout_path' => $transfer->destination_path, 'source_layout' => InstanceSourceLayout::Checkout,
                        'registration_common_repository_path' => null,
                        'vite_port' => DB::table('vite_port_assignments')->where('instance_id', $instance->id)->where('node_id', $transfer->destination_node_id)->value('port'),
                        'ssr_port' => DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->where('node_id', $transfer->destination_node_id)->value('port')]);
                    $this->routes->cutover($snapshot);
                    $transfer->update(['cutover_at' => now(), 'status' => InstanceTransferStatus::InProgress,
                        'current_step' => InstanceTransferStep::Cutover, 'failed_step' => null, 'error_code' => null]);
                } elseif ($checkpoint === 'rollback') {
                    if ($transfer->cutover_at !== null) {
                        throw $this->conflict();
                    }
                    $this->routes->rollback($snapshot);
                    $transfer->update(['destination_route_id' => null, 'status' => InstanceTransferStatus::Failed,
                        'current_step' => InstanceTransferStep::Reserved, 'recovery_evidence' => null]);
                } elseif ($checkpoint === 'retire') {
                    if ($transfer->cutover_at === null || $instance->node_id !== $transfer->destination_node_id) {
                        throw $this->conflict();
                    }
                    $this->routes->retire($snapshot);
                } elseif ($checkpoint !== 'cutover') {
                    throw $this->conflict();
                }
            }
            if (! $operation->status->terminal() && $status === OperationStatus::Succeeded) {
                if ($operation->kind === 'instance.transfer') {
                    if ($transfer->cutover_at === null || $instance->node_id !== $transfer->destination_node_id) {
                        throw $this->conflict();
                    }
                    $transfer->update(['status' => InstanceTransferStatus::Completed, 'current_step' => InstanceTransferStep::Completed,
                        'completed_at' => now(), 'failed_step' => null, 'error_code' => null, 'recovery_evidence' => null]);
                } elseif ($transfer->cutover_at !== null) {
                    throw $this->conflict();
                }
            }
            if ($status->terminal()) {
                $result = ['instance_id' => $instance->id, 'transfer_id' => $transfer->id,
                    'node_id' => $instance->node_id, 'cutover' => $transfer->cutover_at !== null,
                    ...Arr::only($result, ['error_code', 'recovered'])];
            }

            return $this->direct->project($operation, $data, $result);
        });
    }

    private function conflict(): ResourceOperationException
    {
        return new ResourceOperationException('instance.transfer_conflict', 'The transfer placement does not permit this transition.', 409);
    }
}
