<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Orbit\Core\Http\JsonEnvelope;

#[RequiresPermission('extension:enable', servingNode: ServingNode::Gateway)]
final readonly class ExtensionEnableController
{
    public function __construct(
        private GatewayExtensionState $extensionState,
    ) {}

    public function __invoke(string $extension): JsonResponse
    {
        if (! $this->extensionState->isKnownSlug($extension)) {
            return response()->json(
                JsonEnvelope::failure(
                    'extension_unknown',
                    "Unknown Orbit extension [{$extension}].",
                    ['extension' => $extension],
                ),
                422,
            );
        }

        try {
            $this->extensionState->enable($extension);
        } catch (InvalidArgumentException) {
            return response()->json(
                JsonEnvelope::failure(
                    'extension_unknown',
                    "Unknown Orbit extension [{$extension}].",
                    ['extension' => $extension],
                ),
                422,
            );
        }

        return response()->json(JsonEnvelope::success([
            'extension' => $this->extensionState->snapshotFor($extension),
        ]));
    }
}
