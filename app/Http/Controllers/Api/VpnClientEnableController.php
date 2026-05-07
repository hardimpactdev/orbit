<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Vpn\VpnClientMutationResult;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VpnClientEnableController extends VpnControllerSupport
{
    public function __invoke(Request $request, string $name): JsonResponse
    {
        return $this->respond($this->manager()->enable($name, $request->string('totp')->trim()->toString() ?: null));
    }

    protected function respond(VpnClientMutationResult|VpnFailure $result): JsonResponse
    {
        if ($result instanceof VpnFailure) {
            return $this->fail($result, 422);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'client' => [
                        'name' => $result->client->name,
                        'enabled' => $result->client->enabled,
                        'action' => $result->action,
                        'already_enabled' => $result->alreadyInDesiredState,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }
}
