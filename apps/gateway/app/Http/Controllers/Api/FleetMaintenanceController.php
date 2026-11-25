<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\AdmitFleetCliAction;
use App\Actions\Fleet\AdmitFleetRebootAction;
use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Data\Operations\OperationData;
use App\Domain\ConnServer\ConnServerRollout;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\AdmitConnServerRolloutRequest;
use App\Http\Requests\Fleet\AdmitFleetReleaseRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class FleetMaintenanceController extends Controller
{
    #[StartsOperation('fleet.rollout')]
    public function rollout(AdmitFleetReleaseRequest $request, AdmitFleetRolloutAction $action): JsonResponse
    {
        $operation = $action->execute($request->validated('node_ids') === null ? null : ValidatedData::integerList($request->validated('node_ids')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[StartsOperation('fleet.reboot')]
    public function reboot(AdmitFleetReleaseRequest $request, AdmitFleetRebootAction $action): JsonResponse
    {
        $operation = $action->execute($request->validated('node_ids') === null ? null : ValidatedData::integerList($request->validated('node_ids')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[StartsOperation('fleet.cli_converge')]
    public function cli(AdmitFleetReleaseRequest $request, AdmitFleetCliAction $action): JsonResponse
    {
        $operation = $action->fleet($request->validated('node_ids') === null ? null : ValidatedData::integerList($request->validated('node_ids')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    /** Apply a host Conn Server release or withdraw one Linux service. An explicit scope and dry-run choice are required. */
    #[StartsOperation(ConnServerRollout::Kind)]
    public function connServer(AdmitConnServerRolloutRequest $request, ConnServerRollout $rollout): JsonResponse
    {
        $caller = $request->getUserResolver()();
        $operation = $rollout->admit($request->boolean('dry_run'), $request->string('scope')->value(),
            $request->validated('node_ids') === null ? null : ValidatedData::integerList($request->validated('node_ids')), $caller instanceof Node ? $caller->name : null, $request->attributes->getString('orbit.request_id'),
            $request->validated('version') === null ? null : $request->string('version')->value(), $request->boolean('withdraw'),
            $request->validated('canary_operation_id') === null ? null : $request->integer('canary_operation_id'),
            $request->validated('retry_operation_id') === null ? null : $request->integer('retry_operation_id'));

        if (isset($operation->input['retry_operation_id']) && in_array($operation->input['release_stage'] ?? null, ['check', 'canary'], true)
            && ($release = app(ContinueReleaseFleetAction::class)->current()) !== null) {
            AdmitFleetRollout::dispatch($release->id)->afterCommit();
        }

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
