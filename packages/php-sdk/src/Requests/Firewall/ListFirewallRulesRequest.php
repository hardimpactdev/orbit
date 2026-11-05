<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Firewall\FirewallNodeRulesResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListFirewallRulesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly ?int $nodeId = null,
        private readonly bool $managed = false,
        private readonly bool $live = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/firewall-rules';
    }

    /** @return array<string, int|string> */
    protected function defaultQuery(): array
    {
        return array_filter(
            [
                'node' => $this->nodeId,
                'managed' => $this->managed ? '1' : null,
                'live' => $this->live ? '1' : null,
            ],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): FirewallRulesResponse
    {
        $requestId = $this->successRequestId($response);
        $nodes = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $nodes[] = FirewallNodeRulesResponse::fromGatewayData($data, $requestId);
        }

        return new FirewallRulesResponse($nodes, $requestId);
    }
}
