<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The executor install of a converge (ADR 0266 slice 1, ADR 0267 P10; docs/reference/executor.md). Only a Node whose roles enable
 * an action family gets it: the pinned binary from the agent delivery, its plain service, the relay user's one sudo rule, the
 * pinned Gateway keys, the Node id and the per-role allowlist. A Node with several roles enables the union of their families.
 */
final readonly class NodeExecutorInput
{
    public const string Binary = '/usr/local/libexec/orbit-executor';

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

    /** @return array<string,mixed>|null */
    public static function for(Node $node): ?array
    {
        $roles = array_values($node->roles()->get()->map(static fn (NodeRole $assignment): RoleName => $assignment->role)->all());
        $keys = config('orbit.executor.public_keys');

        return self::intent((string) $node->id, $node->user, 'executor-'.AgentDelivery::key($node), $roles,
            app(AgentDelivery::class)->artifacts(), is_array($keys) ? array_values(array_filter($keys, is_string(...))) : []);
    }

    /**
     * @param  list<RoleName>  $roles
     * @param  array<string, array{sha256: string, path?: string, url?: string}>  $artifacts
     * @param  list<string>  $keys
     * @return array<string,mixed>|null
     */
    public static function intent(string $nodeId, string $user, string $artifactKey, array $roles, array $artifacts, array $keys): ?array
    {
        $families = [];
        foreach ($roles as $role) {
            array_push($families, ...(self::RoleFamilies[$role->value] ?? []));
        }
        $families = array_values(array_unique($families));
        sort($families);
        if ($families === []) {
            return null;
        }
        $artifact = $artifacts[$artifactKey] ?? null;
        // Rollout (docs/reference/executor.md#rollout): until the pinned delivery carries the executor and the Gateway pins a key,
        // the converge skips the install and records why. Nothing uses the executor before then. Once both are present the install
        // is required, and a wrong pin fails the converge.
        $skip = match (true) {
            $artifact === null => 'executor.release_missing',
            $keys === [] => 'executor.keys_missing',
            default => null,
        };
        $error = match (true) {
            $skip !== null => null,
            ! isset($artifact['url']) => 'executor.release_invalid',
            count($keys) > 2 => 'executor.keys_invalid',
            default => null,
        };

        return [
            'skip' => $skip,
            'plan_error' => $error,
            'path' => self::Binary,
            'url' => $artifact['url'] ?? null,
            'sha256' => $artifact['sha256'] ?? null,
            'relay_user' => self::RelayUser,
            'node' => $nodeId."\n",
            'enabled' => implode("\n", $families)."\n",
            'restore_threads' => self::RestoreThreads."\n",
            'gateway_keys' => implode("\n", $keys)."\n",
            'unit' => self::unit(),
            'sudoers' => "{$user} ALL=(".self::RelayUser.') NOPASSWD: '.self::Binary." relay\n",
        ];
    }

    /** A plain service: systemd only starts the executor at boot and restarts it after a crash; nothing else runs on its path. */
    public static function unit(): string
    {
        return "[Unit]\nDescription=Orbit executor: Gateway-signed Node actions (ADR 0266)\n\n"
            ."[Service]\nExecStart=".self::Binary." serve\nRestart=always\nRestartSec=1\nStateDirectory=orbit/executor\nStateDirectoryMode=0700\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }
}
