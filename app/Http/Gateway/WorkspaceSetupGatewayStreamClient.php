<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Requests\Workspaces\SetupWorkspaceStreamRequest;

class WorkspaceSetupGatewayStreamClient
{
    public function __construct(
        private readonly ?GatewayStreamTransport $streams = null,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(?string $name, ?string $app, ?string $path, ?string $callerCwd, callable $onEvent): int|GatewayApiException
    {
        return ($this->streams ?? app(GatewayStreamTransport::class))->events(
            request: new SetupWorkspaceStreamRequest($name, $app, $path, $callerCwd),
            onEvent: $onEvent,
            unavailableMessage: 'Gateway connection is required to set up a workspace.',
        );
    }
}
