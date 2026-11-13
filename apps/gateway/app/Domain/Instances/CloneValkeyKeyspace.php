<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

/**
 * The keys of a clone's own Valkey user (docs/reference/development-containers.md#clone-the-database). Its ACL grants only
 * `orbit_instance_<id>_*`, so the clone can never read or write its default's keys. A database index is no boundary: Valkey 8
 * has no ACL rule for it. A Laravel app reaches those keys through the connection and Horizon prefixes that Orbit writes.
 */
final class CloneValkeyKeyspace
{
    /** Commands that would need a channel pattern. A clone's user gets no channels (`resetchannels`). */
    private const array PubSub = ['@pubsub', 'publish', 'spublish', 'subscribe', 'ssubscribe', 'psubscribe'];

    /** FLUSHDB empties the whole index, which the clone shares with its default: the clone never gets it. */
    private const array WholeIndex = ['flushdb'];

    public static function prefix(Instance $instance): string
    {
        return 'orbit_instance_'.$instance->id.'_';
    }

    /** @return list<string> the user's key patterns, without the `~` */
    public static function patterns(Instance $instance): array
    {
        return [self::prefix($instance).'*'];
    }

    /**
     * The commands of the default's user, from its recorded rules, without the `+` and without FLUSHDB. Orbit adds its own deny
     * rules again.
     *
     * @return list<string>
     */
    public static function allow(string $privileges): array
    {
        $allow = [];
        foreach (preg_split('/\s+/', trim($privileges)) ?: [] as $rule) {
            if (str_starts_with($rule, '+')) {
                $allow[] = substr($rule, 1);
            }
        }
        if ($allow === [] || array_intersect(array_map(strtolower(...), $allow), self::PubSub) !== []) {
            throw new ResourceOperationException('instance.database_clone_unsupported',
                "The default Instance's Valkey user grants no commands or grants pub/sub; a clone's own user gets no channels.", 422);
        }

        return array_values(array_unique(array_filter($allow, static fn (string $rule): bool => ! in_array(strtolower($rule), self::WholeIndex, true))));
    }

    /**
     * Laravel's `<PREFIX>_PREFIX` for the connection keys and Horizon's `HORIZON_PREFIX`, each the default form of its framework
     * (`<app>_database_`, `<app>_horizon:`) under the clone's own prefix.
     *
     * @return array<string, string>
     */
    public static function laravelKeys(Instance $instance, string $prefix): array
    {
        return [$prefix.'_PREFIX' => self::prefix($instance).'database_', 'HORIZON_PREFIX' => self::prefix($instance).'horizon:'];
    }
}
