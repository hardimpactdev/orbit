<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Orbit\Sdk\Laravel\Requests\Operations\UpdateAllStreamRequest;

final readonly class UpdateAllGatewayStreamClient
{
    public function __construct(
        private GatewayStreamTransport $streams,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(callable $onEvent): int|GatewayApiException
    {
        return $this->streams->events(
            request: new UpdateAllStreamRequest,
            onEvent: $onEvent,
            unavailableMessage: 'Gateway connection is required to update the fleet.',
            defaultExitCode: 0,
        );
    }
}
