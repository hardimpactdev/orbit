<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Firewall;

use Orbit\Sdk\Responses\Nodes\NodeAgentResponse;
use SensitiveParameter;

/**
 * The firewall rules of one Node. `managed` is present with `--managed`, and `live` and `agent` with `--live`.
 * `live.unmatched` lists the live rules that no recorded or managed rule explains.
 */
final readonly class FirewallNodeRulesResponse
{
    /**
     * @param  list<FirewallRuleResponse>  $rules
     * @param  list<array<string, mixed>>|null  $managed
     * @param  array<string, mixed>|null  $live
     */
    public function __construct(
        public int $nodeId,
        public string $node,
        public array $rules,
        public ?array $managed,
        public ?array $live,
        public ?NodeAgentResponse $agent,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $rules = [];

        foreach (is_array($data['rules'] ?? null) ? $data['rules'] : [] as $rule) {
            $row = self::keyed($rule);

            if ($row !== null) {
                $rules[] = FirewallRuleResponse::fromGatewayData($row, $requestId);
            }
        }

        return new self(
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            node: is_string($data['node'] ?? null) ? $data['node'] : '',
            rules: $rules,
            managed: is_array($data['managed'] ?? null) ? array_values(array_filter(array_map(self::keyed(...), $data['managed']))) : null,
            live: self::keyed($data['live'] ?? null),
            agent: NodeAgentResponse::tryFromGatewayData($data['agent'] ?? null),
        );
    }

    /** @return array<string, mixed>|null */
    private static function keyed(mixed $value): ?array
    {
        return is_array($value) ? array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY) : null;
    }

    /** Whether the live matches may be stale: the Node's agent has gone silent. */
    public function possiblyOutdated(): bool
    {
        return ($this->live['possibly_outdated'] ?? false) === true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node' => $this->node,
            ...($this->live === null ? [] : ['agent' => $this->agent?->toArray()]),
            'rules' => array_map(
                static function (FirewallRuleResponse $rule): array {
                    $data = $rule->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->rules,
            ),
            ...($this->managed === null ? [] : ['managed' => $this->managed]),
            ...($this->live === null ? [] : ['live' => $this->live]),
        ];
    }
}
