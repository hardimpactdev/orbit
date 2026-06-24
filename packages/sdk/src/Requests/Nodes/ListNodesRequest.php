<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListNodesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $role = null,
        public readonly bool $doctor = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'role' => $this->role,
                'doctor' => $this->doctor ? true : null,
            ],
            static fn (mixed $v): bool => $v !== null,
        );
    }

    public function createDtoFromResponse(Response $response): NodeListResponse
    {
        $data = $this->unwrapData($response);
        $envelopeMeta = $this->envelopeMeta($response);
        $nodes = $data['nodes'] ?? [];

        return new NodeListResponse(
            nodes: $this->listOfStringKeyedArrays($nodes),
            meta: $envelopeMeta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeMeta(Response $response): array
    {
        $body = $response->json();

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            return $this->stringKeyedArray($body['success']['meta']);
        }

        return [];
    }
}
