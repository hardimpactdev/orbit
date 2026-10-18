<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * What one composition created: the declared Nodes, the network slot, and every guest's names and addresses. Every
 * guest is a copy of the task VM's `node-template`.
 */
final readonly class TopologyConstructionInputs
{
    public const int SCHEMA = 5;

    /**
     * @param  array<string, array{instance:string,incus_address:string,wireguard_address:string}>  $nodes
     */
    private function __construct(
        public TopologyTarget $target,
        public int $slot,
        public array $nodes,
    ) {
        if ($slot < 1 || $slot > 200) {
            throw new InvalidArgumentException('The topology construction slot is invalid.');
        }
        if (array_keys($nodes) !== $target->recipe->nodeKeys()) {
            throw new InvalidArgumentException('The topology construction Node inventory is incomplete.');
        }
        foreach ($nodes as $key => $node) {
            $recipeNode = $target->recipe->node($key);
            if ($node !== self::node($target, $slot, $recipeNode)) {
                throw new InvalidArgumentException("Topology construction Node [{$key}] is invalid.");
            }
        }
    }

    public static function create(TopologyTarget $target, int $slot): self
    {
        $nodes = [];
        foreach ($target->recipe->nodes as $node) {
            $nodes[$node->key] = self::node($target, $slot, $node);
        }

        return new self($target, $slot, $nodes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->target->issue,
            'attempt_id' => $this->target->attempt->value,
            'recipe' => $this->target->recipe->toArray(),
            'slot' => $this->slot,
            'nodes' => $this->nodes,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (($value['schema'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException(
                'The topology construction schema is not supported; release it with the harness that created it.',
            );
        }
        if (
            array_keys($value) !== ['schema', 'issue', 'attempt_id', 'recipe', 'slot', 'nodes']
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || ! is_array($value['recipe'])
            || ! is_int($value['slot'])
            || ! is_array($value['nodes'])
        ) {
            throw new InvalidArgumentException('The topology construction input schema is invalid.');
        }
        $target = TopologyTarget::for(
            $value['issue'],
            new AttemptId($value['attempt_id']),
            TopologyRecipe::fromArray($value['recipe']),
        );

        return new self(
            $target,
            $value['slot'],
            self::decodeNodes($value['nodes']),
        );
    }

    /** @return array{instance:string,incus_address:string,wireguard_address:string} */
    private static function node(TopologyTarget $target, int $slot, TopologyNode $node): array
    {
        return [
            'instance' => $target->instance($node->key),
            'incus_address' => TopologyTarget::ipv4For($slot, $node->address),
            'wireguard_address' => $node->wireGuardAddress(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return array<string, array{instance:string,incus_address:string,wireguard_address:string}>
     */
    private static function decodeNodes(array $nodes): array
    {
        $decoded = [];
        foreach (SerializedArrays::stringKeyed($nodes) as $key => $node) {
            if (
                ! is_array($node)
                || array_keys($node) !== ['instance', 'incus_address', 'wireguard_address']
                || ! is_string($node['instance'])
                || ! is_string($node['incus_address'])
                || ! is_string($node['wireguard_address'])
            ) {
                throw new InvalidArgumentException('The topology construction Node inventory is invalid.');
            }
            $decoded[$key] = [
                'instance' => $node['instance'],
                'incus_address' => $node['incus_address'],
                'wireguard_address' => $node['wireguard_address'],
            ];
        }

        return $decoded;
    }
}
