<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class ReloadToolRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool).'/reload';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ToolShowResponse
    {
        $data = $this->unwrapData($response);
        $tool = $data['tool'] ?? [];

        return new ToolShowResponse(
            tool: is_array($tool) ? $tool : [],
        );
    }
}
