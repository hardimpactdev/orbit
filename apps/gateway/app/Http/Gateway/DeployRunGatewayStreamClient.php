<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Requests\Deploy\RunDeployStreamRequest;

class DeployRunGatewayStreamClient
{
    public function __construct(
        private readonly ?GatewayStreamTransport $streams = null,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(string $app, bool $detach, callable $onEvent): int|GatewayApiException
    {
        return ($this->streams ?? app(GatewayStreamTransport::class))->events(
            request: new RunDeployStreamRequest($app, $detach),
            onEvent: $onEvent,
            unavailableMessage: 'Gateway connection is required to run deployments.',
        );
    }
}
