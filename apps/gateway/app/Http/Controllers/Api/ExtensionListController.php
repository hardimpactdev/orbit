<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Orbit\Core\Http\JsonEnvelope;

#[RequiresPermission('extension:read', servingNode: ServingNode::Gateway)]
final readonly class ExtensionListController implements Loggable
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

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /extensions';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
