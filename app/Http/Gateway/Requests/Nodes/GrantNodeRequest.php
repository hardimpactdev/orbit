<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class GrantNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $consumingNode,
        public readonly string $servingNode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/grant';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'consuming_node' => $this->consumingNode,
            'serving_node' => $this->servingNode,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeGrantResponse
    {
        $data = $this->unwrapData($response);

        return new NodeGrantResponse(
            consumingNode: is_string($data['consuming_node'] ?? null) ? $data['consuming_node'] : $this->consumingNode,
            servingNode: is_string($data['serving_node'] ?? null) ? $data['serving_node'] : $this->servingNode,
            alreadyGranted: is_bool($data['already_granted'] ?? null) ? $data['already_granted'] : false,
        );
    }
}
