<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleSyncResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class SyncSchedulesRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/schedules/sync';
    }

    public function createDtoFromResponse(Response $response): ScheduleSyncResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $schedules = $data['schedules'] ?? [];

        return new ScheduleSyncResponse(
            schedules: is_array($schedules) ? array_values($schedules) : [],
            meta: $meta,
        );
    }
}
