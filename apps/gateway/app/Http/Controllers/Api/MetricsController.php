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
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class MetricsController extends Controller
{
    public function store(EnableMetricsRequest $request, MetricsRoleManager $manager): JsonResponse
    {
        return $this->response($request, $manager->enable($request->nodeId()), 201);
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

    #[StartsOperation('metrics.exporter_enable')]
    public function enableExporter(Request $request, Node $node, MetricsRoleManager $manager): JsonResponse
    {
        return $this->response($request, $manager->enableExporter($node->id), 202);
    }

    #[StartsOperation('metrics.exporter_disable')]
    public function disableExporter(Request $request, Node $node, MetricsRoleManager $manager): JsonResponse
    {
        return $this->response($request, $manager->disableExporter($node->id), 202);
    }

    private function response(
        Request $request,
        MetricsMutationData|MetricsStatusData|MetricsCredentialsData|Operation $result,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'data' => $result instanceof Operation ? OperationData::fromModel($result)->toArray() : $result->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $status);
    }
}
