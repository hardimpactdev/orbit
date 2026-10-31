<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\AdmitAnalyticsUpdateAction;
use App\Actions\Analytics\SetAnalyticsCredentialsAction;
use App\Actions\Analytics\ShowAnalyticsCredentialsAction;
use App\Actions\Analytics\UnsetAnalyticsCredentialsAction;
use App\Data\Analytics\AnalyticsCredentialsData;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\SetAnalyticsCredentialsRequest;
use App\Http\Requests\Analytics\UpdateAnalyticsRequest;
use App\Http\Requests\Operations\StartsOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class AnalyticsController extends Controller
{
    #[StartsOperation('analytics.update')]
    public function update(UpdateAnalyticsRequest $request, AdmitAnalyticsUpdateAction $action): JsonResponse
    {
        return response()->json([
            'data' => OperationData::fromModel($action->execute($request->version()))->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    public function credentials(Request $request, ShowAnalyticsCredentialsAction $action): JsonResponse
    {
        return $this->credentialsResponse($request, $action->execute());
    }

    public function setCredentials(
        SetAnalyticsCredentialsRequest $request,
        SetAnalyticsCredentialsAction $action,
    ): JsonResponse {
        return $this->credentialsResponse($request, $action->execute($request->apiKey()));
    }

    public function unsetCredentials(Request $request, UnsetAnalyticsCredentialsAction $action): JsonResponse
    {
        return $this->credentialsResponse($request, $action->execute());
    }

    private function credentialsResponse(Request $request, AnalyticsCredentialsData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
