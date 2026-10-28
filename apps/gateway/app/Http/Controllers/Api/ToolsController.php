<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\AdoptToolAction;
use App\Actions\Tools\InstallToolAction;
use App\Actions\Tools\ListToolsAction;
use App\Actions\Tools\RemoveToolAction;
use App\Actions\Tools\UpdateToolAction;
use App\Data\Operations\OperationData;
use App\Data\Tools\ToolData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Tools\AdoptToolRequest;
use App\Http\Requests\Tools\ListToolsRequest;
use App\Http\Requests\Tools\StoreToolRequest;
use App\Models\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::ToolOwning)]
final class ToolsController extends Controller
{
    public function index(ListToolsRequest $request, ListToolsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action
                ->execute($request->nodeId())
                ->map(static fn (Tool $tool): array => ToolData::fromModel($tool)->toArray())
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function show(Request $request, Tool $tool): JsonResponse
    {
        return response()->json([
            'data' => ToolData::fromModel($tool)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('tool.install')]
    public function store(StoreToolRequest $request, InstallToolAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute($request->payload()))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation('tool.adopt')]
    public function adopt(AdoptToolRequest $request, AdoptToolAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute($request->payload()))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation('tool.update')]
    public function update(Request $request, string $tool, UpdateToolAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute(Tool::query()->findOrFail($tool)))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation('tool.remove')]
    public function destroy(Request $request, string $tool, RemoveToolAction $action): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($action->execute(Tool::query()->findOrFail($tool)))->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
