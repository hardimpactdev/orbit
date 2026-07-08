<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Firewall;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Firewall\FirewallRuleMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveFirewallRuleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly string $node,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/firewall-rules/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'node' => $this->node,
            'destructive_consent' => true,
        ];
    }

    public function createDtoFromResponse(Response $response): FirewallRuleMutationResponse
    {
        return new FirewallRuleMutationResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
