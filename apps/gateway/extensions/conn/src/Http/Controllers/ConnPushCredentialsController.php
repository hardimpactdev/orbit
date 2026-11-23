<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\Push\SetConnPushCredentialsAction;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentialsStore;
use Orbit\Extensions\Conn\Http\Requests\SetConnPushCredentialsRequest;

/** Manages Gateway-scoped Apple APNs credentials. Never returns the private key. */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class ConnPushCredentialsController extends Controller
{
    public function show(Request $request, ConnPushCredentialsStore $store): JsonResponse
    {
        return response()->json([
            'data' => $store->status()->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function update(SetConnPushCredentialsRequest $request, SetConnPushCredentialsAction $action): JsonResponse
    {
        $status = $action->execute($request->keyId(), $request->teamId(), $request->privateKey());

        return response()->json([
            'data' => $status->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
