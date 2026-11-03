<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use Orbit\Sdk\Responses\Nodes\NodeAgentResponse;
use SensitiveParameter;

/**
 * A Tool recorded by `tool:adopt`, and the liveness of the Node agent whose report the Gateway checked. While the agent
 * is offline, that report is possibly outdated. A Node without an observing agent has no liveness.
 */
final readonly class AdoptedToolResponse
{
    public string $requestId;

    public function __construct(public ToolResponse $tool, public ?NodeAgentResponse $agent)
    {
        $this->requestId = $tool->requestId;
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] string $requestId): self
    {
        return new self(ToolResponse::fromGatewayData($data, $requestId), NodeAgentResponse::tryFromGatewayData($data['agent'] ?? null));
    }

    /** While the agent is offline, the Tool was checked against facts reported before it went silent. */
    public function possiblyOutdated(): bool
    {
        return $this->agent?->state === 'offline';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [...$this->tool->toArray(), 'agent' => $this->agent?->toArray()];
    }
}
