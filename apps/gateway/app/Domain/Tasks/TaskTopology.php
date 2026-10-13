<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;

/**
 * The test nodes a subtask needs, nested in its group's task VM. Each entry names one node and may add roles after a
 * `+`, such as `app-prod+ingress`. The harness's `acquire --nodes` takes the same entries.
 */
final class TaskTopology
{
    /** The test nodes an entry can name, in composition order. */
    public const array Nodes = ['app-dev', 'app-prod', 'app-prod-2'];

    /**
     * The roles an entry can add to its node, in the order the harness adds them. Each one must converge on a test
     * node; others did not without more setup, so they wait until a task needs them.
     */
    public const array ExtraRoles = ['ingress'];

    /**
     * The most test nodes a group's topology may have. In the spike, a 12 GiB task VM was full with two test nodes
     * beside the test Gateway and the operator, so a third would run it out of memory.
     */
    public const int MaxNodes = 2;

    public const string Invalid = 'Topology must be a list of at most two distinct app-dev, app-prod, or app-prod-2 nodes, each with an optional +ingress role where it fits the node; app-prod-2 needs app-prod.';

    public const string TooMany = 'The subtasks of a group declare more than two test nodes together. A large task VM holds two: split the work into groups.';

    private const array BaseRoles = ['app-dev' => 'app-dev', 'app-prod' => 'app-prod', 'app-prod-2' => 'app-prod'];

    public static function valid(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MaxNodes) {
            return false;
        }
        $nodes = [];
        foreach ($value as $entry) {
            $parsed = is_string($entry) ? self::parse($entry) : null;
            if ($parsed === null || isset($nodes[$parsed[0]])) {
                return false;
            }
            $nodes[$parsed[0]] = true;
        }

        return ! isset($nodes['app-prod-2']) || isset($nodes['app-prod']);
    }

    /**
     * The declaration in canonical form: each entry's roles in {@see self::ExtraRoles} order.
     *
     * @return list<string>
     */
    public static function from(mixed $value): array
    {
        if (! is_array($value) || ! self::valid($value)) {
            throw new ResourceOperationException('tasks.invalid_topology', self::Invalid, 422);
        }

        $entries = [];
        foreach ($value as $entry) {
            [$node, $roles] = is_string($entry) ? self::parse($entry) ?? ['', []] : ['', []];
            $entries[] = self::entry($node, $roles);
        }

        return $entries;
    }

    /**
     * The group's topology: the union of its subtasks that are not cancelled, each node once with every role any of
     * them adds, in node order.
     *
     * @return list<string>
     */
    public static function forGroup(Task $group): array
    {
        return self::union(self::declarations($group));
    }

    /**
     * The stored declarations of the group's subtasks that are not cancelled, without `$except`.
     *
     * @return list<mixed>
     */
    public static function declarations(Task $group, ?Task $except = null): array
    {
        $query = $group->tasks()->where('status', '!=', TaskStatus::Cancelled->value);
        if ($except instanceof Task) {
            $query->whereKeyNot($except->id);
        }

        return array_values($query->pluck('topology')->all());
    }

    /**
     * Each node keeps roles that fit its base role, so only the node count can make a union invalid: more than
     * {@see self::MaxNodes} is refused.
     *
     * @param  list<mixed>  $declarations
     * @return list<string>
     */
    public static function union(array $declarations): array
    {
        $roles = [];
        foreach ($declarations as $declaration) {
            foreach (self::from($declaration ?? []) as $entry) {
                [$node, $extra] = self::parse($entry) ?? ['', []];
                $roles[$node] = [...$roles[$node] ?? [], ...$extra];
            }
        }
        $union = [];
        foreach (self::Nodes as $node) {
            if (isset($roles[$node])) {
                $union[] = self::entry($node, array_values(array_intersect(self::ExtraRoles, $roles[$node])));
            }
        }

        if (count($union) > self::MaxNodes) {
            throw new ResourceOperationException('tasks.invalid_topology', self::TooMany, 422);
        }

        return $union;
    }

    /**
     * The test nodes of a declaration, without their roles.
     *
     * @param  list<string>  $topology
     * @return list<string>
     */
    public static function nodes(array $topology): array
    {
        return array_map(static fn (string $entry): string => explode('+', $entry)[0], $topology);
    }

    /**
     * Whether a topology holds every node and role of another.
     *
     * @param  list<string>  $held
     * @param  list<string>  $needed
     */
    public static function covers(array $held, array $needed): bool
    {
        return self::union([$held, $needed]) === self::union([$held]);
    }

    /** @return array{0: string, 1: list<string>}|null */
    private static function parse(string $entry): ?array
    {
        $parts = explode('+', $entry);
        $node = array_shift($parts);
        if (! isset(self::BaseRoles[$node]) || count($parts) !== count(array_unique($parts))) {
            return null;
        }
        $registry = new RoleRegistry;
        $base = RoleName::from(self::BaseRoles[$node]);
        foreach ($parts as $role) {
            if (! in_array($role, self::ExtraRoles, true) || $registry->conflicts($base, RoleName::from($role))) {
                return null;
            }
        }

        return [$node, array_values(array_intersect(self::ExtraRoles, $parts))];
    }

    /** @param list<string> $roles */
    private static function entry(string $node, array $roles): string
    {
        return implode('+', [$node, ...$roles]);
    }
}
