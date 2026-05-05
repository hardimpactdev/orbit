<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeRemoveResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly string $destructiveConsentSource = 'force',
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => $this->destructiveConsentSource,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeRemoveResponse
    {
        $data = $this->unwrapData($response);

        return new NodeRemoveResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : $this->name,
            removed: is_bool($data['removed'] ?? null) ? $data['removed'] : true,
            removedSelf: is_bool($data['removed_self'] ?? null) ? $data['removed_self'] : false,
            wireguardPeerRemoved: is_bool($data['wireguard_peer_removed'] ?? null) ? $data['wireguard_peer_removed'] : false,
            grantsRemoved: is_int($data['grants_removed'] ?? null) ? $data['grants_removed'] : 0,
        );
    }
}
