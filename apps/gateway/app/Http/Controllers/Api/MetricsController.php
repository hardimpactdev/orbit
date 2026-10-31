<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Metrics\MetricsCredentialsData;
use App\Data\Metrics\MetricsMutationData;
use App\Data\Metrics\MetricsStatusData;
use App\Data\Operations\OperationData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Metrics\MetricsStatusReader;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Metrics\EnableMetricsRequest;
use App\Http\Requests\Metrics\RemoveMetricsRequest;
use App\Http\Requests\Metrics\ResetMetricsCredentialsRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class MetricsController extends Controller
{
    #[StartsOperation('node.role_add')]
    public function store(EnableMetricsRequest $request, MetricsRoleManager $manager): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($manager->enable($request->nodeId()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    public function destroy(RemoveMetricsRequest $request, MetricsRoleManager $manager): JsonResponse
    {
        return $this->response($request, $manager->remove($request->force(), $request->purgeData()));
    }

    public function status(Request $request, MetricsStatusReader $reader): JsonResponse
    {
        return $this->response($request, $reader->status());
    }

    public function credentials(Request $request, MetricsCredentialManager $manager): JsonResponse
    {
        return $this->response($request, $manager->credentials())->header('Cache-Control', 'no-store');
    }

    public function reset(ResetMetricsCredentialsRequest $request, MetricsCredentialManager $manager): JsonResponse
    {
        return $this->response($request, $manager->reset())->header('Cache-Control', 'no-store');
    }

    public function enableExporter(Request $request, Node $node, MetricsRoleManager $manager): JsonResponse
    {
        return $this->response($request, $manager->enableExporter($node->id));
    }

    public function disableExporter(Request $request, Node $node, MetricsRoleManager $manager): JsonResponse
    {
        return $this->response($request, $manager->disableExporter($node->id));
    }

    private function response(
        Request $request,
        MetricsMutationData|MetricsStatusData|MetricsCredentialsData $result,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'data' => $result->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $status);
    }
}
