<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Doctor;

use Orbit\Sdk\Responses\Nodes\NodeAgentResponse;
use Orbit\Sdk\Support\CredentialRedactor;
use SensitiveParameter;

final readonly class DoctorNodeResponse
{
    private const int MAX_NODE_NAME_LENGTH = 255;

    /**
     * @param  list<DoctorFamilyResponse>  $families
     * @param  ?NodeAgentResponse  $agent  the agent's liveness; while it is offline, every finding may be outdated. Null for a Node without an observing agent.
     */
    private function __construct(
        public int $nodeId,
        public string $nodeName,
        public bool $healthy,
        public array $families,
        public ?NodeAgentResponse $agent = null,
    ) {}

    /** While the agent is offline, its findings come from facts reported before it went silent. */
    public function possiblyOutdated(): bool
    {
        return $this->agent?->state === 'offline';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromGatewayData(#[SensitiveParameter] array $data): ?self
    {
        $nodeId = $data['node_id'] ?? null;
        $nodeName = $data['node_name'] ?? null;
        $healthy = $data['healthy'] ?? null;
        $familyData = $data['families'] ?? null;

        if (
            ! is_int($nodeId)
            || $nodeId <= 0
            || ! is_string($nodeName)
            || ! is_bool($healthy)
            || ! is_array($familyData)
            || ! array_is_list($familyData)
        ) {
            return null;
        }

        $redactedNodeName = new CredentialRedactor()->redactText($nodeName);
        if ($redactedNodeName === '' || strlen($redactedNodeName) > self::MAX_NODE_NAME_LENGTH) {
            return null;
        }

        $families = [];
        foreach ($familyData as $familyDatum) {
            if (! is_array($familyDatum)) {
                continue;
            }

            $family = DoctorFamilyResponse::fromGatewayData($familyDatum);
            if ($family !== null) {
                $families[] = $family;
            }
        }

        return new self(nodeId: $nodeId, nodeName: $redactedNodeName, healthy: $healthy, families: $families,
            agent: NodeAgentResponse::tryFromGatewayData($data['agent'] ?? null));
    }

    /** @return array{node_id:int,node_name:string,healthy:bool,families:list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}>,agent:array{state:string,since:string|null}|null} */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node_name' => $this->nodeName,
            'healthy' => $this->healthy,
            'families' => array_map(
                static fn (DoctorFamilyResponse $family): array => $family->toArray(),
                $this->families,
            ),
            'agent' => $this->agent?->toArray(),
        ];
    }
}
