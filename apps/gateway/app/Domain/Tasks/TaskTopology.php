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

    /** The roles an entry can add to its node, in the order the harness adds them. */
    public const array ExtraRoles = ['ingress', 'database', 'websocket'];

    public const string Conflict = 'The group\'s subtasks declare roles that do not fit together on one node.';

    public const string Invalid = 'Topology must be a list of distinct app-dev, app-prod, or app-prod-2 nodes, each with optional +ingress, +database, or +websocket roles that fit the node; app-prod-2 needs app-prod.';

    private const array BaseRoles = ['app-dev' => 'app-dev', 'app-prod' => 'app-prod', 'app-prod-2' => 'app-prod'];

    public static function valid(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > count(self::Nodes)) {
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

        return array_values(array_map(static fn (string $entry): string => self::entry(...self::parse($entry) ?? ['', []]), $value));
    }

    /**
     * The group's topology: the union of its subtasks that are not cancelled, each node once with every role any of
     * them adds, in node order.
     *
     * @return list<string>
     */
    public static function forGroup(Task $group): array
    {
        return self::union(self::forGroupDeclarations($group));
    }

    /**
     * The stored declarations of the group's subtasks that are not cancelled, optionally without one subtask.
     *
     * @return list<mixed>
     */
    public static function forGroupDeclarations(Task $group, ?Task $except = null): array
    {
        return array_values($group->tasks()->where('status', '!=', TaskStatus::Cancelled->value)
            ->when($except instanceof Task, static fn ($query) => $query->whereKeyNot($except?->id))
            ->pluck('topology')->all());
    }

    /**
     * Refuses declarations whose union does not compose, such as two subtasks that add conflicting roles to one node.
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
        if (! self::valid($union)) {
            throw new ResourceOperationException('tasks.invalid_topology', self::Conflict, 422);
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
        return array_values(array_map(static fn (string $entry): string => explode('+', $entry)[0], $topology));
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
        foreach ($parts as $i => $role) {
            foreach (array_slice($parts, $i + 1) as $other) {
                if ($registry->conflicts(RoleName::from($role), RoleName::from($other))) {
                    return null;
                }
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
