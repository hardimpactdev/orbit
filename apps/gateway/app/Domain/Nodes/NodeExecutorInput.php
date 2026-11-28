<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Engine\NodeBusAccess;
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
     * (`app-prod` gets `power` when ADR 0265 brings sleep to production). A VPN Node places the private DNS records (`dns`). `process`
     * (Process start and stop, Node-timer enable) is on app-dev only; app-prod joins in a later rollout. `host` holds the host-resource
     * primitives (files, dotenv keys, access lists, trust, units, configuration sets, HTTP probes) that the Route flows compose
     * (ADR 0271, Actions that predate the rule), so it goes where Route steps run: app-dev and vpn. `incus` holds the Incus primitives (instances, network
     * ACLs, instance devices, storage volumes) and goes where Incus holds the instances: app-dev.
     *
     * @var array<string, list<string>>
     */
    public const array RoleFamilies = [RoleName::AppDev->value => ['firewall', 'host', 'incus', 'instance', 'power', 'process', 'proof', 'route'], RoleName::Vpn->value => ['dns', 'host'],
        // The Gateway's own Node runs the executor too: it joins the bus and reports its clock (ADR 0271 N7).
        RoleName::Gateway->value => ['proof']];

    /**
     * The first agent delivery whose executor knows each newer family. An older executor refuses to start on an unknown family,
     * so the converge enables a family only once the pinned delivery has it.
     *
     * @var array<string, string>
     */
    public const array FamilySince = ['firewall' => '0.16.0', 'route' => '0.17.0', 'dns' => '0.18.0', 'instance' => '0.19.0', 'process' => '0.31.0', 'host' => '0.36.0', 'incus' => '0.39.0'];

    /** @return array<string,mixed>|null */
    public static function for(Node $node): ?array
    {
        $roles = array_values($node->roles()->get()->map(static fn (NodeRole $assignment): RoleName => $assignment->role)->all());
        $keys = config('orbit.executor.public_keys');

        $delivery = app(AgentDelivery::class);
        $intent = self::intent((string) $node->id, $node->user, 'executor-'.AgentDelivery::key($node), $roles,
            $delivery->artifacts(), is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [], $delivery->version());

        // The machine bus (ADR 0271): only the Gateway's address, and the guard that lets only root reach it. A Node holds no bus
        // credential; the Gateway admits it by its WireGuard address. Without the engine's key both files are removed, and the
        // executor serves its socket alone. On the Gateway's own host the guard also admits the Gateway's runtime user, whose
        // processes join with the Gateway's key; any other user there stays out.
        $guard = NodeBusAccess::guard(in_array(RoleName::Gateway, $roles, true) ? $node->user : null);

        return $intent === null ? null : [...$intent, 'unit' => self::unit($guard !== null), 'bus_url' => NodeBusAccess::url(),
            'bus_path' => NodeBusAccess::Path, 'bus_guard' => $guard, 'bus_guard_path' => NodeBusAccess::GuardPath];
    }

    /**
     * @param  list<RoleName>  $roles
     * @param  array<string, array{sha256: string, path?: string, url?: string}>  $artifacts
     * @param  list<string>  $keys
     * @param  string  $version  The Cargo version of the pinned agent delivery.
     * @return array<string,mixed>|null
     */
    public static function intent(string $nodeId, string $user, string $artifactKey, array $roles, array $artifacts, array $keys, string $version): ?array
    {
        $families = [];
        foreach ($roles as $role) {
            array_push($families, ...(self::RoleFamilies[$role->value] ?? []));
        }
        $families = array_values(array_filter(array_unique($families),
            static fn (string $family): bool => version_compare($version, self::FamilySince[$family] ?? '0.0.0', '>=')));
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

    /**
     * A plain service: systemd only starts the executor at boot and restarts it after a crash. With the machine bus, each start
     * first loads the bus guard, so the guard is in place whenever the executor can join the bus.
     */
    public static function unit(bool $busGuard = false): string
    {
        return "[Unit]\nDescription=Orbit executor: Gateway-signed Node actions (ADR 0266)\n\n"
            ."[Service]\n".($busGuard ? 'ExecStartPre=/usr/sbin/nft -f '.NodeBusAccess::GuardPath."\n" : '')
            .'ExecStart='.self::Binary." serve\nRestart=always\nRestartSec=1\nStateDirectory=orbit/executor\nStateDirectoryMode=0700\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }
}
