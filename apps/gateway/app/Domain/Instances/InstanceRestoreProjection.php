<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Publishes no active records until the bound restoration run completed its publication proof. */
final readonly class InstanceRestoreProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    public function refused(Operation $operation): void
    {
        // The shared start-refusal hook is called only when no native run was accepted.
        app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'native_start_not_submitted' => true],
            'result' => [...($operation->result ?? []), 'compensation_complete' => true]]);
    }

    public function input(Operation $operation): array
    {
        $input = $operation->input;
        $copies = ValidatedData::object($input['execution_secrets']);
        foreach (RouteExecutionProof::objects($input['servers']) as $index => $server) {
            $model = DatabaseServer::query()->findOrFail(ValidatedData::integer($server['server_id']));
            $copies['restore_server_'.$index] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                'database-destroy-owned-'.ValidatedData::integer($input['restore_database_intent_id']).'-'.ValidatedData::integer($server['server_id']).'-restore', $model->root_password."\n".ValidatedData::string($server['statements']));
        }
        unset($input['execution_secrets']);

        return ['intent' => $input, 'execution_secrets' => $copies === [] ? (object) [] : $copies];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $direct = app(DirectOperation::class);
            $direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $native = ValidatedData::object($data['result'] ?? []);
            $success = $data['status'] === 'succeeded' && NativeRunResult::stepStatus($native, 'Republish the Route') === 'succeeded';
            $plan = ValidatedData::object($operation->input['route_create']);
            $markerSteps = array_keys(ValidatedData::integerList($plan['site_nodes']));
            $compensated = NativeRunResult::stepStatus($native, 'Republish the Route') === 'skipped'
                || (array_all($markerSteps, static fn (int $index): bool => NativeRunResult::stepStatus($native, 'Compensate publication-'.$index) === 'succeeded')
                    && (ValidatedData::object($native['withdrawal'] ?? [])['ready'] ?? false) === true);
            $settlementFailure = null;
            $detail = ValidatedData::object($data['failure'] ?? [])['detail'] ?? '';
            if (is_string($detail) && preg_match('/instance[.]restore_(database_identity_changed|database_admission_changed|ownership_changed|evidence_missing)/', $detail, $match) === 1) {
                $settlementFailure = $match[0];
            }
            $result = ['restored' => $success, 'instance_id' => $operation->target_id, 'removal_id' => $operation->input['removal_id'],
                'route_id' => $operation->input['route_id']];
            if (! $success) {
                $result['compensation_complete'] = $compensated;
                $data['status'] = 'failed';
                $data['error_code'] = $settlementFailure ?? ($compensated ? 'instance.restore_publication_failed' : 'instance.restore_compensation_failed');
            } else {
                $data['error_code'] = null;
            }
            if (! $operation->status->terminal() && $success) {
                $removal = InstanceRemoval::query()->lockForUpdate()->findOrFail(ValidatedData::string($operation->input['removal_id']));
                $removal->members()->whereKey($operation->input['member_ids'])->update(['abandoned_at' => now()]);
                $removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
                Route::query()->whereKey($operation->input['route_id'])->update(['status' => RouteStatus::Active, 'sites_published' => true,
                    'failed_step' => null, 'error_code' => null]);
                Instance::query()->whereKey($removal->members()->pluck('instance_id'))->update(['status' => InstanceState::Active]);
            }

            return $direct->project($operation, $data, $result);
        });
    }
}
