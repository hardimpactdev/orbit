<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\NodeRole;

/**
 * The Phpactor symbol index that a Laravel default prepares before its seed, so every clone starts with it
 * (docs/reference/development-containers.md#php-symbol-index). The runtime folder ships `phpactor` to every container; only this
 * index is gated, and only here: the Project's default, of a Laravel source, placed on a Node whose one app role is an active
 * `app-dev`. A production Instance, an app-prod or CI-only Node, a clone and a non-Laravel source get no index work.
 */
final readonly class PhpSymbolIndex
{
    /** The bound of one build, within which `agent` runs it at the lowest CPU and I/O priority (swamp/ansible/instance_php_index.py). */
    public const int Seconds = 600;

    /** The exec around the build: the Node's own bound is this much longer, so the build's bound fires first. */
    public const int ExecSeconds = 30;

    /**
     * The container intent's `php_index`: the application's folder, where Phpactor finds the index by itself, the bound, and the
     * runtime folder whose `phpactor` part the run requires unless that folder predates it. Null when the Instance gets no index.
     *
     * @return array{root: string, seconds: int, runtime: string}|null
     */
    public static function intent(Instance $instance): ?array
    {
        $instance->loadMissing('node.roles');
        // placedOnAppDev() holds the Node's one app role to `app-dev` in any state, and a CI-only Node to development too.
        if ($instance->name !== DefaultInstanceName::Name || $instance->source_is_laravel !== true || ! $instance->placedOnAppDev()
            || ! $instance->node->roles->contains(static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active)) {
            return null;
        }

        return ['root' => InstanceContainer::application($instance), 'seconds' => self::Seconds, 'runtime' => InstanceHostIntent::RuntimeFolder.'/current'];
    }

    /** What the index adds to a run's budget: its bound and its exec's, or nothing without an index. */
    public static function runSeconds(mixed $container): int
    {
        return is_array($container) && isset($container['php_index']) ? self::Seconds + self::ExecSeconds : 0;
    }
}
