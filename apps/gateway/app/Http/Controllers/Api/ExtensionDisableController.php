<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\Extensions\GatewayExtensionStorageUnavailable;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Orbit\Core\Http\JsonEnvelope;

#[RequiresPermission('extension:disable', servingNode: ServingNode::Gateway)]
final readonly class ExtensionDisableController
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
            $this->extensionState->disable($extension);
        } catch (GatewayExtensionStorageUnavailable) {
            return response()->json(
                JsonEnvelope::failure(
                    'extension_state_unavailable',
                    'Gateway extension state storage is unavailable.',
                    ['extension' => $extension],
                ),
                503,
            );
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
