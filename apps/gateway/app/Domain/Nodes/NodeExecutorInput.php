<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Models\NodeRole;

/**
 * The executor of a converge (ADR 0266, ADR 0267, ADR 0271 N7; docs/reference/executor.md). The executor is the second process of
 * the one `orbit-agent` binary, so every Node gets it with the agent: its plain service, the relay user's one sudo rule, the pinned
 * Gateway keys, the Node id and the per-role allowlist. A Node with several roles enables the union of their families; a Node whose
 * roles enable none runs the executor with an empty allowlist, which refuses every action.
 */
final readonly class NodeExecutorInput
{
    /** The one agent binary; the executor is its `execute` mode. */
    public const string Binary = '/usr/local/bin/orbit-agent';

    /** The separate binary before ADR 0271 N7. The converge removes it. */
    public const string RetiredBinary = '/usr/local/libexec/orbit-executor';

    public const string RelayUser = 'orbit-executor-relay';

    /**
     * The restore pool's threads (ADR 0266): one pool per Node, sized by the swap device's queue depth, not the CPU count. 32 is the
     * measured size for the current Nodes' swap devices; the executor refuses fewer than 16.
     */
    public const int RestoreThreads = 32;

    /**
     * The families each role enables. The no-op `proof` and sleep/wake/discard `power` families ship on app-dev; `clone` joins with its slice
     * (`app-prod` gets `power` when ADR 0265 brings sleep to production).
     *
     * @var array<string, list<string>>
     */
    public const array RoleFamilies = [RoleName::AppDev->value => ['proof', 'power']];

    /** @return array<string,mixed> */
    public static function for(Node $node): array
    {
        $roles = array_values($node->roles()->get()->map(static fn (NodeRole $assignment): RoleName => $assignment->role)->all());
        $keys = config('orbit.executor.public_keys');

        return self::intent((string) $node->id, $node->user, $roles, is_array($keys) ? array_values(array_filter($keys, is_string(...))) : []);
    }

    /**
     * @param  list<RoleName>  $roles
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    public static function intent(string $nodeId, string $user, array $roles, array $keys): array
    {
        $families = [];
        foreach ($roles as $role) {
            array_push($families, ...(self::RoleFamilies[$role->value] ?? []));
        }
        $families = array_values(array_unique($families));
        sort($families);
        // Until the Gateway pins a key, the converge starts no executor and records why; nothing can sign for it before then.
        $skip = $keys === [] ? 'executor.keys_missing' : null;

        return [
            'skip' => $skip,
            'plan_error' => $skip === null && count($keys) > 2 ? 'executor.keys_invalid' : null,
            'retired_binary' => self::RetiredBinary,
            'relay_user' => self::RelayUser,
            'node' => $nodeId."\n",
            'enabled' => $families === [] ? '' : implode("\n", $families)."\n",
            'restore_threads' => self::RestoreThreads."\n",
            'gateway_keys' => implode("\n", $keys)."\n",
            'unit' => self::unit(),
            'sudoers' => "{$user} ALL=(".self::RelayUser.') NOPASSWD: '.self::Binary." execute relay\n",
        ];
    }

    /** A plain service: systemd only starts the executor at boot and restarts it after a crash; nothing else runs on its path. */
    public static function unit(): string
    {
        return "[Unit]\nDescription=Orbit executor: Gateway-signed Node actions (ADR 0271)\n\n"
            ."[Service]\nExecStart=".self::Binary." execute serve\nRestart=always\nRestartSec=1\nStateDirectory=orbit/executor\nStateDirectoryMode=0700\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }
}
