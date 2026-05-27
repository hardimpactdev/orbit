<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeDefaultResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DefaultNodeSetRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        public readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/default';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['name' => $this->name];
    }

    public function createDtoFromResponse(Response $response): NodeDefaultResponse
    {
        $data = $this->unwrapData($response);
        $name = $data['default_node'] ?? $this->name;

        return new NodeDefaultResponse(
            defaultNode: is_string($name) && $name !== '' ? $name : null,
        );
    }
}
