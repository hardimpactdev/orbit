<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\GatewayExtension;
use App\Services\Extensions\GatewayExtensionState;
use App\Services\Extensions\GatewayExtensionStorageUnavailable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Orbit\Core\Http\JsonEnvelope;

#[RequiresPermission('extension:enable', servingNode: ServingNode::Gateway)]
final class ExtensionEnableController implements Loggable
{
    private ?string $activityExtension = null;

    private ?GatewayExtension $activitySubject = null;

    public function __construct(
        private readonly GatewayExtensionState $extensionState,
    ) {}

    public function __invoke(string $extension): JsonResponse
    {
        $this->activityExtension = $extension;
        $this->activitySubject = null;

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
            $this->activitySubject = $this->extensionState->enable($extension);
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /extensions/{extension}/enable';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return array_filter(
            ['extension' => $this->activityExtension],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );
    }

    public function description(): ?string
    {
        return null;
    }
}
