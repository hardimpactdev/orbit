<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Http\JsonResponse;
use Orbit\Core\Http\JsonEnvelope;

#[RequiresPermission('extension:read', servingNode: ServingNode::Gateway)]
final readonly class ExtensionListController
{
    public function __construct(
        private GatewayExtensionState $extensionState,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(JsonEnvelope::success([
            'extensions' => $this->extensionState->snapshot(),
        ]));
    }
}
