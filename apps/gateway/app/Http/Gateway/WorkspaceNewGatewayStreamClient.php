<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Requests\Workspaces\CreateWorkspaceStreamRequest;

class WorkspaceNewGatewayStreamClient
{
    public function __construct(
        private readonly ?GatewayStreamTransport $streams = null,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(string $name, string $app, string $base, ?string $phpVersion, callable $onEvent): int|GatewayApiException
    {
        return ($this->streams ?? app(GatewayStreamTransport::class))->events(
            request: new CreateWorkspaceStreamRequest($name, $app, $base, $phpVersion),
            onEvent: $onEvent,
            unavailableMessage: 'Gateway connection is required to create a workspace.',
        );
    }
}
