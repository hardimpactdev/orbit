<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Http\Gateway\Requests\Tools\ToolActionStreamRequest;

final readonly class ToolActionGatewayStreamClient
{
    public function __construct(
        private ?GatewayStreamTransport $streams = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(
        string $action,
        ?string $tool,
        array $payload,
        callable $onEvent,
        string $unavailableMessage,
    ): int|GatewayApiException {
        return ($this->streams ?? app(GatewayStreamTransport::class))->events(
            request: new ToolActionStreamRequest($action, $tool, $payload),
            onEvent: $onEvent,
            unavailableMessage: $unavailableMessage,
        );
    }
}
