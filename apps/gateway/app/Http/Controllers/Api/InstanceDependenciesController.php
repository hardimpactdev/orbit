<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\Dependencies\ReadSwampDependenciesAction;
use App\Domain\Instances\InstanceState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DependencyResultsRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class InstanceDependenciesController extends Controller
{
    public function show(DependencyResultsRequest $request, Instance $instance, ReadSwampDependenciesAction $action): JsonResponse
    {
        abort_unless($instance->status === InstanceState::Active && ! $instance->removalMember()->exists(), 409);

        return response()->json([
            'data' => $action->execute($instance->project, $instance, $request->source(), $request->integer('packages_page', 1), $request->integer('findings_page', 1))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ])->header('Cache-Control', 'no-store');
    }
}
