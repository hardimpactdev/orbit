<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\AddAptRepositoryAction;
use App\Actions\Tools\ListAptRepositoriesAction;
use App\Actions\Tools\RemoveAptRepositoryAction;
use App\Data\Operations\OperationData;
use App\Data\Tools\AptRepositoryData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Tools\AddAptRepositoryRequest;
use App\Http\Requests\Tools\ListToolsRequest;
use App\Models\AptRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::ToolOwning)]
final class AptRepositoriesController extends Controller
{
    public function index(ListToolsRequest $request, ListAptRepositoriesAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($request->nodeId())->map(static fn (AptRepository $repository): array => AptRepositoryData::fromModel($repository)->toArray())->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function show(Request $request, string $repository): JsonResponse
    {
        return response()->json(['data' => AptRepositoryData::fromModel(AptRepository::query()->findOrFail($repository))->toArray(), 'meta' => $this->meta($request)]);
    }

    #[StartsOperation('tool.apt_repository_add')]
    public function store(AddAptRepositoryRequest $request, AddAptRepositoryAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute($request->nodeId(), $request->repositoryName(), $request->definition(),
            $request->attributes->getString('orbit.request_id')))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation('tool.apt_repository_remove')]
    public function destroy(Request $request, string $repository, RemoveAptRepositoryAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute((int) $repository, $request->attributes->getString('orbit.request_id')))->toArray(),
            'meta' => $this->meta($request)], 202);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
