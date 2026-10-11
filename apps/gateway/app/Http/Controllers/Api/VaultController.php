<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Vault\SetupVaultAction;
use App\Actions\Vault\ShowVaultStatusAction;
use App\Actions\Vault\SyncVaultAction;
use App\Actions\Vault\TeardownVaultAction;
use App\Data\Vault\VaultStatusData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\SetupVaultRequest;
use App\Http\Requests\Vault\TeardownVaultRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
