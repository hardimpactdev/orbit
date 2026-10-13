<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The Nodes of one topology: the Gateway and the operator always, plus the declared workload Nodes, all from the
 * {@see TopologyNodeCatalog}. The declared list alone determines every Node, its role image, address, roles, and
 * checkout. An entry names one workload Node and may add roles after a `+`, such as `app-prod+ingress`; the composer
 * adds them with `orbit node:role:add`, so the Gateway's own role rules decide whether they fit.
 */
final readonly class TopologyRecipe
{
    /** @var list<TopologyNode> */
    public array $nodes;

    /** @var array<string, TopologyNode> */
    private array $nodesByKey;

    /** @param list<string> $workloads canonical entries, such as `app-prod+ingress` */
    private function __construct(public array $workloads)
    {
        $catalog = TopologyNodeCatalog::load();
        $extras = [];
        foreach ($workloads as $workload) {
            [$key, $extra] = self::entry($workload);
            $extras[$key] = $extra;
        }
        $nodes = [];
        foreach ($catalog->nodes as $key => $entry) {
            if ($entry['declarable'] && ! isset($extras[$key])) {
                continue;
            }
            $nodes[] = new TopologyNode(
                $key,
                $entry['image'],
                match (true) {
                    $entry['declarable'] => TopologyNodePurpose::Workload,
                    $entry['image'] === RoleImage::Gateway => TopologyNodePurpose::Gateway,
                    default => TopologyNodePurpose::Operator,
                },
                $entry['address'],
                $entry['checkout'],
                $entry['declarable'] ? [(string) $entry['base_role'], ...$extras[$key]] : $entry['image']->programRoles(),
                $entry['memory'],
                $entry['tld'],
            );
        }

        $this->nodes = $nodes;
        $byKey = [];
        foreach ($nodes as $node) {
            $byKey[$node->key] = $node;
        }
        $this->nodesByKey = $byKey;
    }

    /**
     * @param  array<array-key, mixed>  $workloads
     */
    public static function compose(array $workloads): self
    {
        $catalog = TopologyNodeCatalog::load();
        $declared = [];
        foreach ($workloads as $workload) {
            if (! is_string($workload)) {
                throw new InvalidArgumentException('A declared Node must be one of '.implode(', ', $catalog->declarable()).'.');
            }
            [$key, $extra] = self::entry($workload);
            if (isset($declared[$key])) {
                throw new InvalidArgumentException("The Node [{$key}] is declared twice.");
            }
            $declared[$key] = implode('+', [$key, ...$extra]);
        }
        foreach (array_keys($declared) as $key) {
            $requires = $catalog->node($key)['requires'];
            if ($requires !== null && ! isset($declared[$requires])) {
                throw new InvalidArgumentException("The Node [{$key}] requires [{$requires}].");
            }
        }

        return new self(array_values(array_filter(array_map(
            static fn (string $key): ?string => $declared[$key] ?? null,
            $catalog->declarable(),
        ))));
    }

    /**
     * One entry's Node key and its extra roles in canonical order.
     *
     * @return array{0:string, 1:list<string>}
     */
    private static function entry(string $entry): array
    {
        $catalog = TopologyNodeCatalog::load();
        $parts = explode('+', $entry);
        $key = array_shift($parts);
        if (! in_array($key, $catalog->declarable(), true)) {
            throw new InvalidArgumentException('A declared Node must be one of '.implode(', ', $catalog->declarable()).'.');
        }
        foreach ($parts as $role) {
            if (! in_array($role, $catalog->extraRoles, true)) {
                throw new InvalidArgumentException("The Node [{$key}] can only add the roles ".implode(', ', $catalog->extraRoles).'.');
            }
        }
        if (count($parts) !== count(array_unique($parts))) {
            throw new InvalidArgumentException("The Node [{$key}] adds a role twice.");
        }

        return [$key, array_values(array_intersect($catalog->extraRoles, $parts))];
    }

    /** The `--nodes` option: absent gives the default, an empty string gives no workload Node. */
    public static function fromOption(?string $option): self
    {
        if ($option === null) {
            return self::compose(TopologyNodeCatalog::load()->defaults);
        }
        $option = trim($option);

        return self::compose($option === '' ? [] : array_map(trim(...), explode(',', $option)));
    }

    /** @return array{workloads:list<string>} */
    public function toArray(): array
    {
        return ['workloads' => $this->workloads];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== ['workloads'] || ! is_array($value['workloads']) || ! array_is_list($value['workloads'])) {
            throw new InvalidArgumentException('The topology recipe schema is invalid.');
        }
        $recipe = self::compose($value['workloads']);
        if ($recipe->workloads !== $value['workloads']) {
            throw new InvalidArgumentException('The topology recipe declares its Nodes out of order.');
        }

        return $recipe;
    }

    /** @return list<string> */
    public function nodeKeys(): array
    {
        return array_keys($this->nodesByKey);
    }

    /** The last address position the recipe uses, which ends the network's DHCP range. */
    public function lastAddress(): int
    {
        return max(array_map(static fn (TopologyNode $node): int => $node->address, $this->nodes));
    }

    public function vmCount(): int
    {
        return count(array_filter($this->nodes, static fn (TopologyNode $node): bool => ! $node->isContainer()));
    }

    /** @return list<string> */
    public function checkoutNodeKeys(): array
    {
        return array_values(array_map(
            static fn (TopologyNode $node): string => $node->key,
            array_filter($this->nodes, static fn (TopologyNode $node): bool => $node->checkout),
        ));
    }

    /**
     * The roles a workload Node gets beyond the base role of its image, which the composer adds.
     *
     * @return list<string>
     */
    public function extraRoles(TopologyNode $node): array
    {
        return array_slice($node->roles, 1);
    }

    /**
     * The extra roles of a Node that put it in its own Cluster `e2e-<node>` first, as `ingress` needs.
     *
     * @return list<string>
     */
    public function ownClusterRoles(TopologyNode $node): array
    {
        return array_values(array_intersect($this->extraRoles($node), TopologyNodeCatalog::load()->ownClusterRoles));
    }

    /** @return list<TopologyNode> */
    public function workloadNodes(): array
    {
        return array_values(array_filter(
            $this->nodes,
            static fn (TopologyNode $node): bool => $node->purpose === TopologyNodePurpose::Workload,
        ));
    }

    /** @return array<string, list<string>> */
    public function assignments(): array
    {
        $assignments = [];
        foreach ($this->nodes as $node) {
            $assignments[$node->key] = $node->roles;
        }

        return $assignments;
    }

    /** @return list<RoleImage> */
    public function images(): array
    {
        $images = [];
        foreach ($this->nodes as $node) {
            $images[$node->image->value] = $node->image;
        }

        return array_values($images);
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodesByKey[$key]);
    }

    public function node(string $key): TopologyNode
    {
        return $this->nodesByKey[$key] ?? throw new InvalidArgumentException("The topology has no Node [{$key}].");
    }
}
