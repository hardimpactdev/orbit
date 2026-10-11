<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use Closure;
use OpenSSLCertificate;

/**
 * Lets every task VM host copy the base image from every other host, Incus to Incus, never through the
 * Gateway. The members are the configured hosts whose Node is active. Each member:
 *
 * - listens for its peers on one address, port 8443: its LAN address when every peer is in its Cluster and has
 *   a LAN address, otherwise its WireGuard address, and never on every address;
 * - has its own client certificate in {@see ClientDirectory}, which only root reads;
 * - trusts each peer's client certificate as `orbit-task-image-<peer id>`, restricted to its own project;
 * - names each peer as the remote `node-<peer id>` in {@see ClientDirectory}, with the peer's server certificate
 *   pinned from the Gateway's own read of it;
 * - admits each peer's address on that port with the managed rule `orbit:task-vm-image:<peer id>`.
 *
 * {@see converge()} makes all of that exact on every member and removes what a former member left on the
 * others. {@see retire()} removes it from a host that is no longer configured.
 */
final readonly class TaskVmImagePeers
{
    public const string ClientDirectory = '/etc/orbit/task-vms/incus';

    public const string TrustPrefix = 'orbit-task-image-';

    private const string RemotePrefix = 'node-';

    /** Creates the client directory and the client certificate once, and prints the certificate. */
    private const string Identity = <<<'BASH'
        install -d -m 0700 -o root -g root -- "$1"
        if [ ! -s "$1/client.crt" ] || [ ! -s "$1/client.key" ]; then
            INCUS_CONF="$1" incus remote generate-certificate >/dev/null
        fi
        cat -- "$1/client.crt"
        BASH;

    /** Trusts the client certificate on stdin as `$1`, restricted to the project `$2`. */
    private const string AddTrust = <<<'BASH'
        certificate=$(mktemp)
        trap 'rm -f -- "$certificate"' EXIT
        cat >"$certificate"
        incus config trust add-certificate "$certificate" --name "$1" --restricted --projects "$2"
        BASH;

    /**
     * Replaces the remotes: `config.yml` from stdin, and one pinned server certificate for each `<remote>` and
     * `<base64 PEM>` pair after the directory.
     */
    private const string Remotes = <<<'BASH'
        directory=$1
        shift
        install -d -m 0700 -o root -g root -- "$directory"
        next=$(mktemp -d -- "$directory/servercerts.XXXXXX")
        trap 'rm -rf -- "$next" "$directory/config.yml.next"' EXIT
        while [ "$#" -gt 0 ]; do
            printf '%s' "$2" | base64 -d >"$next/$1.crt"
            shift 2
        done
        (umask 077 && cat >"$directory/config.yml.next")
        rm -rf -- "$directory/servercerts"
        mv -T -- "$next" "$directory/servercerts"
        mv -fT -- "$directory/config.yml.next" "$directory/config.yml"
        BASH;

    public function __construct(
        private TaskVmSettings $settings,
        private IncusHost $incus,
        private NodeFirewallRuleCatalog $catalog,
        private NativeNodeRoleFirewallManager $firewall,
    ) {}

    /** The remote name of `$node` in a peer's client configuration. */
    public static function remote(Node $node): string
    {
        return self::RemotePrefix.$node->id;
    }

    /**
     * The configured hosts whose Node is active, in config order.
     *
     * @return list<array{TaskVmHost, Node}>
     */
    public function members(): array
    {
        $nodes = Node::query()
            ->whereKey(array_map(static fn (TaskVmHost $host): int => $host->nodeId, $this->settings->hosts))
            ->where('status', LifecycleStatus::Active)
            ->get()
            ->keyBy('id');
        $members = [];
        foreach ($this->settings->hosts as $host) {
            $node = $nodes->get($host->nodeId);
            if ($node instanceof Node) {
                $members[] = [$host, $node];
            }
        }

        return $members;
    }

    /**
     * The address on which Incus on `$node` listens for its peers, or null when it has none: its LAN address
     * when every peer is in its Cluster and has a LAN address, else its WireGuard address.
     *
     * @param  list<Node>  $peers
     */
    public function listenAddress(Node $node, array $peers): ?string
    {
        if ($peers === []) {
            return null;
        }

        return self::onLan($node, $peers) ? (string) $node->lan_ip : self::wireguard($node);
    }

    /**
     * The rules that admit each peer to the Incus API of `$node`, from the address the peer reaches it from.
     *
     * @return list<UfwManagedRule>
     */
    public function firewallRules(Node $node): array
    {
        $members = $this->members();
        if (! in_array($node->id, array_map(static fn (array $member): int => $member[1]->id, $members), true)) {
            return [];
        }
        $peers = self::peersOf($node, $members);
        $address = $this->listenAddress($node, $peers);
        if ($address === null) {
            return [];
        }
        $lan = self::onLan($node, $peers);

        return array_map(fn (Node $peer): UfwManagedRule => $this->catalog->taskVmImagePeer(
            $peer->id,
            $lan ? (string) $peer->lan_ip : self::wireguard($peer),
            $address,
            ! $lan,
        ), $peers);
    }

    /**
     * Makes listen addresses, client certificates, trust, remotes, and firewall rules exact on every member.
     * Stops at the first host that fails: what it changed on earlier hosts is valid on its own.
     *
     * @param  (Closure(string): void)|null  $progress  hears each Node name when its part is done
     */
    public function converge(?Closure $progress = null): void
    {
        $members = $this->members();
        // A peer's certificate is restricted to the project, so the project's own restrictions keep the peer from
        // root on the host. Every member's project is restricted before any host listens or trusts a peer.
        foreach ($members as [$host, $node]) {
            $this->restrict($host, $node);
        }
        $addresses = [];
        $projects = [];
        $identities = [];
        foreach ($members as [$host, $node]) {
            $addresses[$node->id] = $this->listenAddress($node, self::peersOf($node, $members));
            $projects[$node->id] = $host->project;
            $this->listen($node, $addresses[$node->id]);
            // A lone host needs no certificate: it only removes what former peers left.
            if (count($members) > 1) {
                $identities[$node->id] = $this->identity($node);
            }
        }
        foreach ($members as [$host, $node]) {
            $peers = self::peersOf($node, $members);
            $this->trust($node, $host->project, array_map(static fn (Node $peer): array => [$peer, $identities[$peer->id]['client']], $peers));
            $this->remotes($node, array_map(static fn (Node $peer): array => [
                $peer, (string) $addresses[$peer->id], $projects[$peer->id], $identities[$peer->id]['server'],
            ], $peers));
            $this->reconcileFirewall($node, $this->firewallRules($node));
            if ($progress instanceof Closure) {
                $progress($node->name);
            }
        }
    }

    /**
     * Removes the image peer setup of a Node that is no longer a configured host. First it converges the remaining
     * hosts, which removes their trust, remote, and firewall rule for the Node. Then it removes the Node's own
     * listen address, trust entries, client directory, and rules. A Node record that is gone only converges.
     */
    public function retire(int $nodeId): void
    {
        if ($this->settings->hasHost($nodeId)) {
            throw new TaskVmException('task_vm.host_configured', "Node [{$nodeId}] is still in task_vms.incus.hosts. Remove its entry first.");
        }
        $this->converge();
        $node = Node::query()->find($nodeId);
        if ($node instanceof Node) {
            $this->listen($node, null);
            $this->trust($node, '', []);
            $this->checked($node, 'remove the client directory', $this->incus->root($node, ['rm', '-rf', '--', self::ClientDirectory]));
            $this->reconcileFirewall($node, []);
        }
    }

    /**
     * Restricts the host's task VM project to what task VMs need, as `incus-host.sh` does, and reads the
     * restrictions back.
     *
     * @throws TaskVmException `task_vm.host_not_prepared` when the project is missing or keeps no restriction
     */
    private function restrict(TaskVmHost $host, Node $node): void
    {
        $restrictions = ['restricted' => 'true', 'restricted.networks.access' => $host->network];
        $set = $this->incus->root($node, ['incus', 'project', 'set', $host->project, 'restricted=true', "restricted.networks.access={$host->network}"]);
        foreach ($restrictions as $key => $value) {
            $read = $set->succeeded() ? $this->incus->root($node, ['incus', 'project', 'get', $host->project, $key]) : $set;
            if (! $read->succeeded() || trim($read->stdout) !== $value) {
                $detail = trim(implode("\n", array_slice(explode("\n", trim($read->stderr)), -3)));
                throw new TaskVmException('task_vm.host_not_prepared', "The Incus project [{$host->project}] on Node [{$node->name}] is not restricted, so it cannot trust a peer host. Run task-vms:prepare-host on Node [{$node->name}].".($detail === '' ? '' : "\n{$detail}"));
            }
        }
    }

    /**
     * Sets `core.https_address` to `$address` on port 8443, or unsets an address Orbit set when `$address` is
     * null. Orbit owns the setting only while it is unset or names one of the host's own addresses on that port.
     */
    private function listen(Node $node, ?string $address): void
    {
        $port = NodeFirewallRuleCatalog::IncusPort;
        $current = trim($this->checked($node, 'config get core.https_address', $this->incus->root($node, ['incus', 'config', 'get', 'core.https_address']))->stdout);
        $owned = $current === '' || in_array($current, array_map(
            static fn (string $own): string => "{$own}:{$port}",
            array_filter([$node->lan_ip, $node->wireguard_ip], is_string(...)),
        ), true);
        $desired = $address === null ? '' : "{$address}:{$port}";
        if ($current === $desired) {
            return;
        }
        if (! $owned) {
            if ($address === null) {
                return;
            }
            throw new TaskVmException('task_vm.incus_address_conflict', "Incus on Node [{$node->name}] listens on [{$current}], not on an address of the Node with port {$port}. Unset core.https_address or set it to [{$desired}].");
        }
        $this->checked($node, 'config core.https_address', $this->incus->root($node, $address === null
            ? ['incus', 'config', 'unset', 'core.https_address']
            : ['incus', 'config', 'set', 'core.https_address', $desired]));
    }

    /**
     * The host's client certificate, made once, and its server certificate as Incus reports it.
     *
     * @return array{client: string, server: string}
     */
    private function identity(Node $node): array
    {
        $client = $this->checked($node, 'remote generate-certificate', $this->incus->root($node, ['bash', '-ceu', self::Identity, '--', self::ClientDirectory]));
        $server = IncusHost::json("the Incus server of Node [{$node->name}]", $this->checked($node, 'query /1.0', $this->incus->root($node, ['incus', 'query', '/1.0'])));
        $environment = $server['environment'] ?? null;

        return [
            'client' => self::certificate($node, 'client', $client->truncated ? null : $client->stdout),
            'server' => self::certificate($node, 'server', is_array($environment) ? $environment['certificate'] ?? null : null),
        ];
    }

    /**
     * Makes the host's `orbit-task-image-*` trust entries exactly one restricted client certificate per peer.
     *
     * @param  list<array{Node, string}>  $peers  each peer with its client certificate
     */
    private function trust(Node $node, string $project, array $peers): void
    {
        $subject = "the trust store of Node [{$node->name}]";
        $entries = IncusHost::json($subject, $this->checked($node, 'config trust list', $this->incus->root($node, ['incus', 'config', 'trust', 'list', '--format', 'json'])));
        $desired = [];
        foreach ($peers as [$peer, $certificate]) {
            $desired[self::TrustPrefix.$peer->id] = $certificate;
        }
        $kept = [];
        foreach ($entries as $entry) {
            $name = is_array($entry) ? $entry['name'] ?? null : null;
            $fingerprint = is_array($entry) ? $entry['fingerprint'] ?? null : null;
            if (! is_string($name) || ! str_starts_with($name, self::TrustPrefix)) {
                continue;
            }
            if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw IncusHost::invalid($subject, 'an entry has no fingerprint');
            }
            $certificate = $desired[$name] ?? null;
            if ($certificate !== null && ! isset($kept[$name]) && $fingerprint === self::fingerprint($certificate)
                && ($entry['type'] ?? null) === 'client' && ($entry['restricted'] ?? null) === true && ($entry['projects'] ?? null) === [$project]) {
                $kept[$name] = true;

                continue;
            }
            $this->checked($node, "config trust remove {$name}", $this->incus->root($node, ['incus', 'config', 'trust', 'remove', $fingerprint]));
        }
        foreach ($desired as $name => $certificate) {
            if (! isset($kept[$name])) {
                $this->checked($node, "config trust add-certificate {$name}", $this->incus->root($node, ['bash', '-ceu', self::AddTrust, '--', $name, $project], $certificate));
            }
        }
    }

    /**
     * Replaces the host's client configuration with one remote for each peer.
     *
     * @param  list<array{Node, string, string, string}>  $peers  each peer with its address, project, and server certificate
     */
    private function remotes(Node $node, array $peers): void
    {
        $config = "default-remote: local\nremotes:\n  local:\n    addr: unix://\n    public: false\n";
        $certificates = [];
        foreach ($peers as [$peer, $address, $project, $certificate]) {
            $config .= '  '.self::remote($peer).":\n"
                ."    addr: https://{$address}:".NodeFirewallRuleCatalog::IncusPort."\n"
                ."    auth_type: tls\n"
                ."    project: {$project}\n"
                ."    protocol: incus\n"
                ."    public: false\n";
            array_push($certificates, self::remote($peer), base64_encode($certificate));
        }
        $this->checked($node, 'write the remotes', $this->incus->root($node, ['bash', '-ceu', self::Remotes, '--', self::ClientDirectory, ...$certificates], $config));
    }

    /**
     * @param  list<array{TaskVmHost, Node}>  $members
     * @return list<Node>
     */
    private static function peersOf(Node $node, array $members): array
    {
        return array_values(array_filter(
            array_map(static fn (array $member): Node => $member[1], $members),
            static fn (Node $peer): bool => $peer->id !== $node->id,
        ));
    }

    /** @param  list<Node>  $peers */
    private static function onLan(Node $node, array $peers): bool
    {
        return self::lanAddress($node) !== null && $node->cluster_id !== null && array_all(
            $peers,
            static fn (Node $peer): bool => $peer->cluster_id === $node->cluster_id && self::lanAddress($peer) !== null,
        );
    }

    private static function lanAddress(Node $node): ?string
    {
        return is_string($node->lan_ip) && filter_var($node->lan_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $node->lan_ip : null;
    }

    private static function wireguard(Node $node): string
    {
        return is_string($node->wireguard_ip) && filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? $node->wireguard_ip
            : throw new TaskVmException('task_vm.unknown_host', "Host Node [{$node->name}] has no WireGuard address.");
    }

    /** One PEM certificate, normalized to what OpenSSL exports. */
    private static function certificate(Node $node, string $kind, mixed $pem): string
    {
        $certificate = is_string($pem) ? openssl_x509_read($pem) : false;
        if (! $certificate instanceof OpenSSLCertificate || ! openssl_x509_export($certificate, $exported)) {
            throw IncusHost::invalid("the Incus {$kind} certificate of Node [{$node->name}]", 'it is not one PEM certificate');
        }

        return $exported;
    }

    private static function fingerprint(string $pem): string
    {
        $certificate = openssl_x509_read($pem);

        return $certificate instanceof OpenSSLCertificate ? (string) openssl_x509_fingerprint($certificate, 'sha256') : '';
    }

    /** @param  list<UfwManagedRule>  $rules */
    private function reconcileFirewall(Node $node, array $rules): void
    {
        try {
            $this->firewall->reconcileTaskVmImagePeers($node, $rules, $node->user);
        } catch (FirewallOperationException $exception) {
            throw new TaskVmException($exception->errorCode, $exception->getMessage(), 502, $exception);
        }
    }

    private function checked(Node $node, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw IncusHost::failed("the image peers of Node [{$node->name}]", $operation, $result);
    }
}
