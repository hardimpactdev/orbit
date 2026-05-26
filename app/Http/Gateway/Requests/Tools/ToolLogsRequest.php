<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Tools\ToolLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ToolLogsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $app = null,
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
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
            'lines' => $this->lines,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ToolLogsResponse
    {
        $data = $this->unwrapData($response);
        $logs = $data['logs'] ?? [];

        return new ToolLogsResponse(
            logs: is_array($logs) ? $logs : [],
        );
    }
}
