<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\SchedulerHeartbeatResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class StoreSchedulerHeartbeatRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $heartbeatAt,
        public readonly ?string $registrySyncedAt = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules/heartbeat';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'heartbeat_at' => $this->heartbeatAt,
            'registry_synced_at' => $this->registrySyncedAt,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): SchedulerHeartbeatResponse
    {
        $data = $this->unwrapData($response);
        $state = $data['state'] ?? [];

        return new SchedulerHeartbeatResponse(
            state: is_array($state) ? $state : [],
        );
    }
}
