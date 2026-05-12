<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Contracts\ToolLogGatewayStream;
use App\Http\Gateway\Requests\Tools\ToolLogsStreamRequest;

final readonly class ToolLogGatewayStreamClient implements ToolLogGatewayStream
{
    public function __construct(
        private ?GatewayStreamTransport $streams = null,
    ) {}

    /**
     * @param  callable(string): void  $onOutput
     */
    public function follow(string $tool, ?string $node, ?string $app, int $lines, callable $onOutput): int|GatewayApiException
    {
        return ($this->streams ?? app(GatewayStreamTransport::class))->text(
            request: new ToolLogsStreamRequest($tool, $app, $node, $lines),
            onOutput: $onOutput,
            unavailableMessage: 'Gateway connection is required to read tool logs.',
        );
    }
}
