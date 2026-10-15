<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Http\Controllers;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Vault\Actions\SetupVaultAction;
use Orbit\Extensions\Vault\Actions\ShowVaultStatusAction;
use Orbit\Extensions\Vault\Actions\SyncVaultAction;
use Orbit\Extensions\Vault\Actions\TeardownVaultAction;
use Orbit\Extensions\Vault\Data\VaultStatusData;
use Orbit\Extensions\Vault\Http\Requests\SetupVaultRequest;
use Orbit\Extensions\Vault\Http\Requests\TeardownVaultRequest;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class VaultController extends Controller
{
    public function status(Request $request, ShowVaultStatusAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function setup(SetupVaultRequest $request, SetupVaultAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute($request->payload()));
    }

    public function sync(Request $request, SyncVaultAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function teardown(TeardownVaultRequest $request, TeardownVaultAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute($request->keepItems()));
    }

    private function statusResponse(Request $request, VaultStatusData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
