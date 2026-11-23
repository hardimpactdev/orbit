<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Orbit\Extensions\Conn\Actions\Push\RecordConnAttentionEventAction;
use Orbit\Extensions\Conn\Domain\ConnServerAccess;
use Orbit\Extensions\Conn\Http\Requests\RecordConnAttentionRequest;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;

/** Admits attention and attention_cleared events from native and managed Conn Servers. */
final class ConnAttentionController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function native(
        RecordConnAttentionRequest $request,
        ConnEnvironment $environment,
        ConnServerAccess $access,
        RecordConnAttentionEventAction $action,
    ): JsonResponse {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');
        $access->assert($caller, $environment);

        $result = $action->execute($environment, $request->payload());

        return response()->json([
            'data' => $result,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $result['deduplicated'] ? 200 : 202);
    }

    public function managed(
        RecordConnAttentionRequest $request,
        RecordConnAttentionEventAction $action,
    ): JsonResponse {
        $target = $request->attributes->get('conn.managed_target');
        assert($target instanceof ConnManagedTarget);

        $environment = ConnEnvironment::query()->findOrFail($target->conn_environment_id);
        $result = $action->execute($environment, $request->payload());

        return response()->json([
            'data' => $result,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $result['deduplicated'] ? 200 : 202);
    }
}
