<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Http\Controllers;

use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\AiRouter\Actions\DisableAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\EnableAiRouterAction;
use Orbit\Extensions\AiRouter\Actions\ListAiRouterModelsAction;
use Orbit\Extensions\AiRouter\Actions\ListAiRouterProvidersAction;
use Orbit\Extensions\AiRouter\Actions\ShowAiRouterProviderAction;
use Orbit\Extensions\AiRouter\Actions\ShowAiRouterStatusAction;
use Orbit\Extensions\AiRouter\Actions\UpdateAiRouterAccountAction;
use Orbit\Extensions\AiRouter\Data\AiRouterModelData;
use Orbit\Extensions\AiRouter\Data\AiRouterStatusData;
use Orbit\Extensions\AiRouter\Domain\AiRouterModel;
use Orbit\Extensions\AiRouter\Http\Requests\EnableAiRouterRequest;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class AiRouterController extends Controller
{
    public function store(EnableAiRouterRequest $request, EnableAiRouterAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute($request->payload()), 201);
    }

    public function destroy(Request $request, DisableAiRouterAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function status(Request $request, ShowAiRouterStatusAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function index(Request $request, ListAiRouterProvidersAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute());
    }

    public function show(Request $request, string $provider, ShowAiRouterProviderAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute($provider));
    }

    #[StartsOperation('ai_router.account_update')]
    public function enableAccount(Request $request, string $account, UpdateAiRouterAccountAction $action): JsonResponse
    {
        return $this->operationResponse($request, $action->execute($account, false, $request->attributes->getString('orbit.request_id')));
    }

    #[StartsOperation('ai_router.account_update')]
    public function disableAccount(Request $request, string $account, UpdateAiRouterAccountAction $action): JsonResponse
    {
        return $this->operationResponse($request, $action->execute($account, true, $request->attributes->getString('orbit.request_id')));
    }

    public function models(Request $request, ListAiRouterModelsAction $action): JsonResponse
    {
        return $this->payload($request, array_map(
            static fn (AiRouterModel $model): array => new AiRouterModelData($model->id, $model->provider)->toArray(),
            $action->execute(),
        ));
    }

    private function operationResponse(Request $request, Operation $operation): JsonResponse
    {
        return $this->payload($request, OperationData::fromModel($operation)->toArray(), 202);
    }

    private function statusResponse(Request $request, AiRouterStatusData $result, int $status = 200): JsonResponse
    {
        return $this->payload($request, $result->toArray(), $status);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function payload(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $status);
    }
}
