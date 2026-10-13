<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSize;
use App\Models\Task;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * The test nodes a subtask needs, nested in its group's task VM. Each entry names one node and may add roles after a
 * `+`, such as `app-prod+ingress`. The harness's `acquire --nodes` takes the same entries.
 *
 * This class holds the node catalog: every rule, request validation, message, and generated spec about declarable
 * nodes derives from {@see self::Nodes}, {@see self::Requires}, and {@see self::ExtraRoles}, and the most nodes a
 * topology may have from the largest {@see TaskVmSize}. A parity test checks the harness's catalog against it.
 *
 * Input is validated once, at the request boundary ({@see self::fromInput()}). A stored declaration is trusted
 * ({@see self::fromStored()}, the Eloquent cast), so a stored row never fails inside the scheduler or a serializer.
 */
final readonly class TaskTopology implements Castable
{
    /** The test nodes an entry can name, in composition order, each with its base role. */
    public const array Nodes = ['app-dev' => 'app-dev', 'app-prod' => 'app-prod', 'app-prod-2' => 'app-prod'];

    /** A node that needs another node in the same declaration. */
    public const array Requires = ['app-prod-2' => 'app-prod'];

    /**
     * The roles an entry can add to its node, in the order the harness adds them. Each one must converge on a test
     * node; others did not without more setup, so they wait until a task needs them.
     */
    public const array ExtraRoles = ['ingress'];

    /**
     * The syntax of one entry, for request validation and the generated specs. A unit test checks that it accepts
     * exactly the catalog's node and role names; role conflicts and {@see self::Requires} are checked by
     * {@see self::valid()}.
     */
    public const string EntryPattern = '/\A(?:app-dev|app-prod|app-prod-2)(?:\+ingress)?\z/';

    /** @param array<string, list<string>> $entries node => added roles, in declaration order */
    public function __construct(private array $entries = []) {}

    /** The most test nodes a group's topology may have: what the largest task VM size holds. */
    public static function maxNodes(): int
    {
        return max(array_map(static fn (TaskVmSize $size): int => $size->resources()->testNodes, TaskVmSize::cases()));
    }

    public static function invalidMessage(): string
    {
        $nodes = array_keys(self::Nodes);
        $last = array_pop($nodes);
        $roles = implode(' or ', array_map(static fn (string $role): string => '+'.$role, self::ExtraRoles));
        $requires = implode('; ', array_map(static fn (string $node, string $needed): string => "{$node} needs {$needed}", array_keys(self::Requires), self::Requires));

        return 'Topology must be a list of at most '.self::maxNodes().' distinct '.implode(', ', $nodes).', or '.$last.' nodes, each with an optional '
            .$roles.' role where it fits the node'.($requires === '' ? '' : '; '.$requires).'.';
    }

    public static function tooManyMessage(): string
    {
        $max = self::maxNodes();

        return "The subtasks of a group declare more than {$max} test nodes together. The largest task VM holds {$max}: split the work into groups.";
    }

    public static function valid(mixed $value): bool
    {
        return self::parseInput($value) !== null;
    }

    /**
     * A declaration from a request, CLI file, or task definition, in canonical form: each entry's roles in
     * {@see self::ExtraRoles} order.
     *
     * @throws ResourceOperationException when the declaration is invalid
     */
    public static function fromInput(mixed $value): self
    {
        return self::parseInput($value) ?? throw new ResourceOperationException('tasks.invalid_topology', self::invalidMessage(), 422);
    }

    /**
     * A declaration Orbit stored after {@see self::fromInput()}. It is not validated again: a node or role that left
     * the catalog since stays in it, and the harness reports it.
     *
     * @param  list<string>|null  $value
     */
    public static function fromStored(?array $value): self
    {
        $entries = [];
        foreach ($value ?? [] as $entry) {
            $parts = explode('+', (string) $entry);
            $node = array_shift($parts);
            $entries[$node] = array_values(array_unique([...$entries[$node] ?? [], ...$parts]));
        }

        return new self($entries);
    }

    /**
     * The group's topology: the union of its subtasks that are not cancelled, each node once with every role any of
     * them adds, in catalog order.
     */
    public static function forGroup(Task $group): self
    {
        return self::union(self::declarations($group));
    }

    /**
     * The stored declarations of the group's subtasks that are not cancelled, without `$except`.
     *
     * @return list<self>
     */
    public static function declarations(Task $group, ?Task $except = null): array
    {
        $query = $group->tasks()->where('status', '!=', TaskStatus::Cancelled->value);
        if ($except instanceof Task) {
            $query->whereKeyNot($except->id);
        }

        return array_values($query->get(['id', 'topology'])->map(static fn (Task $task): self => $task->topology)->all());
    }

    /**
     * Each node once with every role any declaration adds, in catalog order.
     *
     * @param  list<self>  $declarations
     */
    public static function union(array $declarations): self
    {
        $roles = [];
        foreach ($declarations as $declaration) {
            foreach ($declaration->entries as $node => $extra) {
                $roles[$node] = [...$roles[$node] ?? [], ...$extra];
            }
        }
        $entries = [];
        foreach (self::ordered(array_keys($roles), array_keys(self::Nodes)) as $node) {
            $entries[$node] = self::ordered(array_values(array_unique($roles[$node])), self::ExtraRoles);
        }

        return new self($entries);
    }

    /**
     * Refuses declarations whose union has more nodes than the largest task VM holds. Each node keeps roles that fit
     * its base role, so only the node count can make a union of valid declarations invalid.
     *
     * @param  list<self>  $declarations
     *
     * @throws ResourceOperationException
     */
    public static function assertFits(array $declarations): void
    {
        if (self::union($declarations)->count() > self::maxNodes()) {
            throw new ResourceOperationException('tasks.invalid_topology', self::tooManyMessage(), 422);
        }
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * The test nodes, without their roles.
     *
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_map(strval(...), array_keys($this->entries));
    }

    /** Whether this topology holds every node and role of another. */
    public function covers(self $needed): bool
    {
        foreach ($needed->entries as $node => $roles) {
            if (! isset($this->entries[$node]) || array_diff($roles, $this->entries[$node]) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The entries, such as `app-prod+ingress`.
     *
     * @return list<string>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (string|int $node, array $roles): string => implode('+', [(string) $node, ...$roles]),
            array_keys($this->entries),
            $this->entries,
        );
    }

    /** @param array<string, mixed> $arguments */
    public static function castUsing(array $arguments): CastsAttributes
    {
        /** @implements CastsAttributes<TaskTopology, TaskTopology|list<string>|null> */
        return new class implements CastsAttributes, SerializesCastableAttributes
        {
            /** @param array<string, mixed> $attributes */
            public function get(Model $model, string $key, mixed $value, array $attributes): TaskTopology
            {
                try {
                    $decoded = is_string($value) ? json_decode($value, true, 8, JSON_THROW_ON_ERROR) : null;
                } catch (JsonException) {
                    $decoded = null;
                }

                return TaskTopology::fromStored(is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : null);
            }

            /** @param array<string, mixed> $attributes */
            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                if ($value === null) {
                    return null;
                }
                $topology = $value instanceof TaskTopology ? $value : TaskTopology::fromStored(is_array($value) ? $value : null);

                return json_encode($topology->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }

            /**
             * @param  array<string, mixed>  $attributes
             * @return list<string>
             */
            public function serialize(Model $model, string $key, mixed $value, array $attributes): array
            {
                return $value instanceof TaskTopology ? $value->toArray() : [];
            }
        };
    }

    /** @return self|null null when the declaration is invalid */
    private static function parseInput(mixed $value): ?self
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::maxNodes()) {
            return null;
        }
        $registry = new RoleRegistry;
        $entries = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                return null;
            }
            $roles = explode('+', $entry);
            $node = array_shift($roles);
            if (! isset(self::Nodes[$node]) || isset($entries[$node]) || count($roles) !== count(array_unique($roles))) {
                return null;
            }
            $base = RoleName::from(self::Nodes[$node]);
            foreach ($roles as $role) {
                if (! in_array($role, self::ExtraRoles, true) || $registry->conflicts($base, RoleName::from($role))) {
                    return null;
                }
            }
            $entries[$node] = array_values(array_intersect(self::ExtraRoles, $roles));
        }
        foreach (self::Requires as $node => $needed) {
            if (isset($entries[$node]) && ! isset($entries[$needed])) {
                return null;
            }
        }

        return new self($entries);
    }

    /**
     * The items in `$order`, then the others in their own order.
     *
     * @param  list<string>  $items
     * @param  list<string>  $order
     * @return list<string>
     */
    private static function ordered(array $items, array $order): array
    {
        return [...array_values(array_intersect($order, $items)), ...array_values(array_diff($items, $order))];
    }
}
