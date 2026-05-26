<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayStreamRequest;
use Saloon\Enums\Method;

final class ToolLogsStreamRequest extends GatewayStreamRequest
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
        return '/api/tools/'.rawurlencode($this->tool).'/logs/stream';
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

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/plain',
        ];
    }
}
