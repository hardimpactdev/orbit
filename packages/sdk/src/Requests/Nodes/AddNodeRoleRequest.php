<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeRoleMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddNodeRoleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $node,
        public readonly string $role,
        public readonly array $settings = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->node}/roles";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'role' => $this->role,
            'settings' => $this->settings,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeRoleMutationResponse
    {
        $data = $this->unwrapData($response);

        return new NodeRoleMutationResponse(
            node: is_string($data['node'] ?? null) ? $data['node'] : $this->node,
            assignment: is_array($data['assignment'] ?? null) ? $data['assignment'] : [],
        );
    }
}
