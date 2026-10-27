<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ReadTaskSwampRunsAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskSwampRunsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function show(Request $request, Task $group, ReadTaskSwampRunsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($group)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ])->header('Cache-Control', 'no-store');
    }
}
