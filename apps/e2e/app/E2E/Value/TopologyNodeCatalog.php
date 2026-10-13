<?php

declare(strict_types=1);

namespace App\E2E\Value;

use RuntimeException;

/**
 * The one list of Nodes a topology can hold, read from `resources/topology-nodes.json`: each Node's role image,
 * address position, checkout mount, memory, and, for a declarable workload Node, its base role, TLD, and the Node it
 * requires. The recipe, the role image builders, composition, and verification all derive from it. The Gateway's
 * own topology catalog has a parity test that reads the same file.
 *
 * @phpstan-type Entry array{image:RoleImage,address:int,checkout:bool,memory:string,declarable:bool,base_role:?string,tld:?string,requires:?string}
 */
final readonly class TopologyNodeCatalog
{
    public const string FILE = __DIR__.'/../../../resources/topology-nodes.json';

    /**
     * @param  array<string, Entry>  $nodes  in composition order
     * @param  list<string>  $defaults
     * @param  list<string>  $extraRoles
     * @param  list<string>  $ownClusterRoles
     */
    private function __construct(
        public array $nodes,
        public array $defaults,
        public array $extraRoles,
        public array $ownClusterRoles,
    ) {}

    public static function load(): self
    {
        static $catalog = null;

        return $catalog ??= self::fromJson((string) file_get_contents(self::FILE));
    }

    public static function fromJson(string $json): self
    {
        $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! is_array($value['nodes'] ?? null)) {
            throw new RuntimeException('The topology node catalog is invalid.');
        }
        $nodes = [];
        foreach ($value['nodes'] as $key => $node) {
            if (! is_string($key) || ! is_array($node)) {
                throw new RuntimeException('The topology node catalog is invalid.');
            }
            TopologyNode::assertKey($key);
            $nodes[$key] = [
                'image' => RoleImage::from(self::string($node, 'image')),
                'address' => self::int($node, 'address'),
                'checkout' => self::bool($node, 'checkout'),
                'memory' => self::string($node, 'memory'),
                'declarable' => self::bool($node, 'declarable'),
                'base_role' => self::optional($node, 'base_role'),
                'tld' => self::optional($node, 'tld'),
                'requires' => self::optional($node, 'requires'),
            ];
        }

        return new self($nodes, self::strings($value, 'default'), self::strings($value, 'extra_roles'), self::strings($value, 'own_cluster_roles'));
    }

    /** @return list<string> the declarable workload Node keys, in composition order */
    public function declarable(): array
    {
        return array_keys(array_filter($this->nodes, static fn (array $node): bool => $node['declarable']));
    }

    /** @return Entry */
    public function node(string $key): array
    {
        return $this->nodes[$key] ?? throw new RuntimeException("The topology node catalog has no Node [{$key}].");
    }

    /** The memory of the first Node that starts from the image; its builder gets the same. */
    public function memoryFor(RoleImage $image): string
    {
        foreach ($this->nodes as $node) {
            if ($node['image'] === $image) {
                return $node['memory'];
            }
        }

        throw new RuntimeException("The topology node catalog has no Node from the image [{$image->value}].");
    }

    /** @param array<array-key, mixed> $node */
    private static function string(array $node, string $key): string
    {
        return is_string($node[$key] ?? null) ? $node[$key] : throw new RuntimeException("The topology node catalog field [{$key}] is invalid.");
    }

    /** @param array<array-key, mixed> $node */
    private static function optional(array $node, string $key): ?string
    {
        return isset($node[$key]) ? self::string($node, $key) : null;
    }

    /** @param array<array-key, mixed> $node */
    private static function int(array $node, string $key): int
    {
        return is_int($node[$key] ?? null) ? $node[$key] : throw new RuntimeException("The topology node catalog field [{$key}] is invalid.");
    }

    /** @param array<array-key, mixed> $node */
    private static function bool(array $node, string $key): bool
    {
        return is_bool($node[$key] ?? null) ? $node[$key] : throw new RuntimeException("The topology node catalog field [{$key}] is invalid.");
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function strings(array $value, string $key): array
    {
        $list = $value[$key] ?? null;
        if (! is_array($list) || ! array_is_list($list) || array_filter($list, is_string(...)) !== $list) {
            throw new RuntimeException("The topology node catalog field [{$key}] is invalid.");
        }

        return $list;
    }
}
