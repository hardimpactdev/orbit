<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessInstallationBatch;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Stores the physical placement selected by the native terminal result, including later retirement failures. */
final readonly class NativeInstanceTransferProjection implements DirectOperationProjection, NativeOperationProjection, DirectOperationWorkflowInput, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct, private NativeRouteTransferPlan $routes) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (!in_array($callerNodeId, ValidatedData::integerList($operation->input['participant_node_ids'] ?? []), true)) {
            throw $this->conflict();
        }
    }

    public function input(Operation $operation): array
    {
        return $this->publicInput($operation, ValidatedData::integer($operation->node_id));
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);
        return ['direct_transfer' => $operation->input['direct_transfer'], 'process_runtime' => app(InstanceTransferProcesses::class)->input($operation, $callerNodeId)];
    }

    public function workflowInput(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['direct_transfer']);
        $publication = ValidatedData::object($operation->input['route_publication']);
        $instance = Instance::query()->findOrFail($operation->target_id);
        $processes = $copies = [];
        foreach (['source', 'destination'] as $side) {
            $batch = app(ProcessInstallationBatch::class)->nativeInput($operation, $side.'_installations');
            $processes[$side] = array_map(static fn(array $member): array => [...$member,
                'control_intent' => Arr::only($member['intent'], ['control','ssh_execution']),
                'secret_field' => 'node_'.$member['node_id'].'_member_'.$member['id']], $batch['members']);
            $copies = [...$copies, ...$batch['execution_secrets']];
        }

        return ['intent' => [...$plan, 'route_mutations' => $publication['route_mutations'], 'workloads' => $publication['workloads'], 'processes' => $processes],
            'execution_secrets' => [...ValidatedData::object($publication['execution_secrets']), ...$copies]];
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
            $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['cutover', 'restored', 'transfer_id', 'error_code']);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $snapshot = ValidatedData::object($operation->input['route_transfer']);
            if (! $operation->status->terminal() && $status->terminal()) {
                if (($result['cutover'] ?? false) === true && $transfer->cutover_at === null) {
                    app(InstanceTransferProcesses::class)->cutover($instance, $transfer, ValidatedData::object($operation->input['process_installations']));
                    $instance->update(['node_id' => $transfer->destination_node_id, 'name' => $transfer->destination_name,
                        'checkout_path' => $transfer->destination_path, 'source_layout' => InstanceSourceLayout::Checkout,
                        'registration_common_repository_path' => null,
                        'vite_port' => DB::table('vite_port_assignments')->where('instance_id', $instance->id)->where('node_id', $transfer->destination_node_id)->value('port'),
                        'ssr_port' => DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->where('node_id', $transfer->destination_node_id)->value('port')]);
                    $this->routes->cutover($snapshot);
                    $transfer->update(['cutover_at' => now(), 'current_step' => InstanceTransferStep::Cutover]);
                }
                if (($result['restored'] ?? false) === true && $transfer->cutover_at === null) {
                    $this->routes->rollback($snapshot);
                    $transfer->update(['destination_route_id' => null]);
                }
                if ($status === OperationStatus::Succeeded && $transfer->cutover_at !== null) {
                    $this->routes->retire($snapshot);
                    foreach (['vite_port_assignments', 'ssr_port_assignments'] as $table) {
                        DB::table($table)->where('instance_id', $instance->id)->where('node_id', $transfer->source_node_id)->delete();
                    }
                    $transfer->update(['status' => InstanceTransferStatus::Completed, 'current_step' => InstanceTransferStep::Completed,
                        'completed_at' => now(), 'failed_step' => null, 'error_code' => null, 'recovery_evidence' => null]);
                } else {
                    $transfer->update(['status' => InstanceTransferStatus::Failed, 'error_code' => $data['error_code'] ?? $result['error_code'] ?? 'instance.transfer_failed']);
                }
            }
            $result = ['instance_id' => $instance->id, 'transfer_id' => $transfer->id,
                'node_id' => $instance->node_id, ...$result];

            return $this->direct->project($operation, $data, $result);
        });
    }

    private function conflict(): ResourceOperationException
    {
        return new ResourceOperationException('instance.transfer_conflict', 'The transfer placement does not permit this transition.', 409);
    }
}
