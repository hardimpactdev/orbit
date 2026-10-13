<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The Nodes of one topology: the Gateway and the operator always, plus the declared workload Nodes.
 * The declared list alone determines every Node, its role image, address, roles, and checkout.
 */
final readonly class TopologyRecipe
{
    /** The workload Nodes a topology can declare, in composition order. */
    public const array WORKLOADS = ['app-dev', 'app-prod', 'app-prod-2'];

    /** What `acquire` composes when the caller declares nothing. */
    public const array DEFAULT_WORKLOADS = ['app-dev', 'app-prod'];

    /** @var list<TopologyNode> */
    public array $nodes;

    /** @var array<string, TopologyNode> */
    private array $nodesByKey;

    /** @param list<string> $workloads */
    private function __construct(public array $workloads)
    {
        $nodes = [new TopologyNode('gateway', RoleImage::Gateway, TopologyNodePurpose::Gateway, 10, true, ['gateway', 'vpn'])];
        foreach ($workloads as $workload) {
            $nodes[] = match ($workload) {
                'app-dev' => new TopologyNode('app-dev', RoleImage::AppDev, TopologyNodePurpose::Workload, 11, true, ['app-dev']),
                'app-prod' => new TopologyNode('app-prod', RoleImage::AppProd, TopologyNodePurpose::Workload, 12, false, ['app-prod']),
                'app-prod-2' => new TopologyNode('app-prod-2', RoleImage::AppProd, TopologyNodePurpose::Workload, 13, false, ['app-prod']),
                default => throw new InvalidArgumentException("The Node [{$workload}] cannot be declared."),
            };
        }
        $nodes[] = new TopologyNode('operator', RoleImage::Operator, TopologyNodePurpose::Operator, 14, true, []);

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
        $declared = [];
        foreach ($workloads as $workload) {
            if (! is_string($workload) || ! in_array($workload, self::WORKLOADS, true)) {
                throw new InvalidArgumentException(
                    'A declared Node must be one of '.implode(', ', self::WORKLOADS).'.',
                );
            }
            if (isset($declared[$workload])) {
                throw new InvalidArgumentException("The Node [{$workload}] is declared twice.");
            }
            $declared[$workload] = true;
        }
        if (isset($declared['app-prod-2']) && ! isset($declared['app-prod'])) {
            throw new InvalidArgumentException('The Node [app-prod-2] requires [app-prod].');
        }

        return new self(array_values(array_filter(
            self::WORKLOADS,
            static fn (string $workload): bool => isset($declared[$workload]),
        )));
    }

    /** The `--nodes` option: absent gives the default, an empty string gives no workload Node. */
    public static function fromOption(?string $option): self
    {
        if ($option === null) {
            return self::compose(self::DEFAULT_WORKLOADS);
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
