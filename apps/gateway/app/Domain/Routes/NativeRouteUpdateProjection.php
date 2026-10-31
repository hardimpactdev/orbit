<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Apply the native terminal observation; no host work or fresh intent rendering. */
final readonly class NativeRouteUpdateProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['route_update'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $operation = app(DirectOperation::class)->project($operation, $data, $result);
            if ($wasTerminal || ! $operation->status->terminal()) {
                return $operation;
            }
            $intent = ValidatedData::object($operation->input['route_update']);
            $source = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['route_id']));
            $selected = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['result_route_id']));
            $renamed = $source->id !== $selected->id;
            if ($operation->status === OperationStatus::Succeeded) {
                if ($renamed) {
                    app(RouteMutationState::class)->cutover($source, $selected);
                }
                $selected->update([...ValidatedData::object($intent['selected']),
                    ...($intent['effectful'] ? ['status' => RouteStatus::Active, 'sites_published' => true,
                        'replacement_step' => ($intent['public_activated'] ?? false) ? RouteReplacementStep::IngressFirewall : null,
                        'failed_step' => null, 'error_code' => null] : []),
                    ...($renamed ? ['replaces_route_id' => null,
                        ...(! $intent['effectful'] ? ['status' => RouteStatus::Pending, 'sites_published' => false, 'replacement_step' => null] : [])] : [])]);
                if ($renamed) {
                    $source->update(['replaced_by_route_id' => null]);
                    $source->delete();
                }
            } elseif (($result['restored'] ?? false) === true) {
                if ($renamed) {
                    $source->update(['replaced_by_route_id' => null]);
                    $selected->delete();
                }
            } elseif ($intent['effectful']) {
                if ($renamed) {
                    $selected->update(['failed_step' => 'native-update', 'error_code' => $operation->error_code]);
                }
            }
            if ($operation->status === OperationStatus::Succeeded || ($result['restored'] ?? false) === true) {
                foreach (($result['restored'] ?? false) ? ['rollback'] : ['certificates_ready', 'public_verified', 'placement_cleanup', 'complete'] as $stage) {
                    app(AnalyticsRoutePlan::class)->checkpointUpdate(RouteExecutionProof::objects($intent['analytics_changes'] ?? []), $stage);
                }
                app(NativeRouteLanInput::class)->recordMigrations(
                    ValidatedData::integerList($result['migrated_node_ids'] ?? []), ValidatedData::integerList($result['migrated_node_ids'] ?? []));
            }

            return $operation;
        });
    }
}
