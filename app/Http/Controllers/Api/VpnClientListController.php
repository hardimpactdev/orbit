<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VpnClientListController extends VpnControllerSupport
{
    public function __invoke(Request $request): JsonResponse
    {
        $clients = $this->manager()->list($this->totp($request));

        return response()->json([
            'success' => [
                'data' => [
                    'clients' => array_map(fn ($client): array => $client->toArray(), $clients),
                ],
                'meta' => ['count' => count($clients)],
            ],
        ]);
    }
}
