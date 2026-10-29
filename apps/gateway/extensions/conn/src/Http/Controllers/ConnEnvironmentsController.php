<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Actions\RegisterConnEnvironmentAction;
use Orbit\Extensions\Conn\Data\ConnEnvironmentData;
use Orbit\Extensions\Conn\Http\Requests\RegisterConnEnvironmentRequest;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Symfony\Component\HttpFoundation\Response;

final class ConnEnvironmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ConnEnvironment::query()
                ->with('node')
                ->orderBy('label')
                ->orderBy('id')
                ->get()
                ->map(static fn (ConnEnvironment $environment): array => ConnEnvironmentData::fromModel($environment)->toArray())
                ->values()
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    #[StartsOperation('conn.gateway_sessions_revoke', bindCaller: true)]
    public function cleanup(Request $request, ConnEnvironment $environment, AdmitConnCleanupAction $action): Response
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $operation = $action->execute($environment, $caller);

        return $operation === null ? response()->noContent()
            : response()->json(['data' => OperationData::fromModel($operation)->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    public function store(RegisterConnEnvironmentRequest $request, RegisterConnEnvironmentAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => ConnEnvironmentData::fromModel($action->execute($node, $request->payload()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
