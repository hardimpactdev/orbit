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
use Orbit\Extensions\Conn\Actions\AdmitConnSessionHoldAction;
use Orbit\Extensions\Conn\Data\ConnHeldSessionData;
use Orbit\Extensions\Conn\Domain\ConnHeldSessions;
use Orbit\Extensions\Conn\Domain\ConnServerAccess;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

/**
 * The calling Node's held session on a container T3 server ([Held sessions](/reference/conn#held-sessions)). A held session answers at
 * once; without one, the read starts the one Operation that makes it and answers 202, and the next read after it returns the session.
 */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnSessionsController extends Controller
{
    // A read answers the current state: an open hold of the Node dedupes concurrent first reads, so no key replays a stale 202.
    #[StartsOperation(ConnHeldSessions::Kind, keyed: false)]
    public function show(Request $request, ConnEnvironment $environment, ConnServerAccess $access, ConnHeldSessions $held, AdmitConnSessionHoldAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');
        $access->assert($caller, $environment);
        $meta = ['request_id' => $request->attributes->getString('orbit.request_id')];

        $current = $held->current($caller, $environment) ?? $action->hold($caller, $environment);
        if ($current instanceof ConnPairing) {
            $current->setRelation('environment', $environment);

            return response()->json(['data' => ConnHeldSessionData::fromModel($current)->toArray(), 'meta' => $meta])->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => OperationData::fromModel($current)->toArray(), 'meta' => $meta], 202);
    }
}
