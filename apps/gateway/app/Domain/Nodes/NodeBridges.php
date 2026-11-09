<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The bridge networks the fleet records: each task VM host's task bridges in Node `settings.task_vms`, and each Node's
 * Instance bridge. No two of them overlap (docs/reference/development-containers.md#nodes).
 */
final readonly class NodeBridges
{
    /**
     * The task bridges of stored task VM host settings: `cidr` and `containers.cidr`.
     *
     * @param  array<array-key, mixed>  $host
     * @return list<string>
     */
    public static function taskBridges(array $host): array
    {
        $containers = is_array($host['containers'] ?? null) ? $host['containers'] : [];

        return array_values(array_filter([$host['cidr'] ?? null, $containers['cidr'] ?? null], is_string(...)));
    }

    /**
     * Every recorded bridge: each Node's Instance bridge and each host's task bridges, without the task bridges of
     * `$replacing`, a Node whose task VM host settings are being replaced.
     *
     * @return list<string>
     */
    public static function recorded(?int $replacing = null): array
    {
        $bridges = [];
        foreach (Node::query()->get(['id', 'settings', 'instance_bridge']) as $node) {
            $stored = $node->settings;
            $host = $node->id !== $replacing && is_array($stored) && is_array($stored['task_vms'] ?? null) ? $stored['task_vms'] : [];
            $bridges = [...$bridges, ...self::taskBridges($host), ...($node->instance_bridge === null ? [] : [$node->instance_bridge])];
        }

        return $bridges;
    }

    /**
     * Takes SQLite's writer lock in the open transaction before any bridge is read, with a write that changes nothing. A bridge
     * allocation and a task host's new bridges then never read the same recorded bridges and both write: the second waits.
     */
    public static function lock(Node $node): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The bridge lock needs an open transaction.');
        }
        DB::table('nodes')->where('id', $node->id)->update(['instance_bridge' => DB::raw('instance_bridge')]);
    }

    /** @param  list<string>  $bridges */
    public static function overlapsAny(string $cidr, array $bridges): bool
    {
        return array_any($bridges, static fn (string $bridge): bool => TaskVmHost::overlap($cidr, $bridge));
    }
}
