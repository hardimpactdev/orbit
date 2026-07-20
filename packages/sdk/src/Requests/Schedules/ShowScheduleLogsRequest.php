<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Schedules;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Schedules\ScheduleLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowScheduleLogsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
        public readonly ?string $instance = null,
        public readonly ?string $node = null,
        public readonly ?int $run = null,
        public readonly ?int $lines = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules/'.rawurlencode($this->name).'/logs';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'instance' => $this->instance,
                'node' => $this->node,
                'run' => $this->run,
                'lines' => $this->lines,
            ],
            fn (string|int|null $value): bool => $value !== null && $value !== '',
        );
    }

    public function createDtoFromResponse(Response $response): ScheduleLogsResponse
    {
        return new ScheduleLogsResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
