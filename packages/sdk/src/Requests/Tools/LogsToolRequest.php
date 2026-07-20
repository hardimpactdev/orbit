<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Tools;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Tools\ToolLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class LogsToolRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $instance = null,
        public readonly ?string $node = null,
        public readonly int $lines = 100,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool).'/logs';
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
                'lines' => $this->lines,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): ToolLogsResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $lineCount = $meta['line_count'] ?? 0;

        return new ToolLogsResponse(
            logs: $this->stringKeyedArray($data['logs'] ?? []),
            lineCount: is_int($lineCount) ? $lineCount : 0,
        );
    }
}
