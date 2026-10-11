<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use App\Models\TaskVm;
use Closure;

/**
 * Lets every task VM host send its base image to every other host over SSH, host to host, never through the
 * Gateway. The members are the configured hosts whose Node is active. Each member:
 *
 * - has its own transfer key pair in {@see KeyDirectory}, which only root reads;
 * - has the receiver {@see Receiver}, which reads one image on stdin and imports it into the host's project;
 * - accepts each peer's public key in its managed user's `authorized_keys`, marked `orbit-task-image-<peer id>`,
 *   with `from="<peer address>"`, `restrict`, and the receiver as the forced command, so a peer key runs nothing
 *   else.
 *
 * Two hosts use their LAN addresses when both are in one Cluster and have one, else their WireGuard addresses.
 *
 * Hosts once copied images over the Incus API. {@see converge()} also removes what that left: the Incus listen
 * address, the peer trust entries, the client directory, and the `orbit:task-vm-image` firewall rules.
 */
final readonly class TaskVmImagePeers
{
    /** Holds the host's transfer key pair, `id_ed25519` and `id_ed25519.pub`. Root only. */
    public const string KeyDirectory = '/etc/orbit/task-vms/image-transfer';

    /** The forced command of every peer key. Its source is `resources/task-vms/image-receive.sh`. */
    public const string Receiver = '/usr/local/libexec/orbit/task-vm-image-receive';

    /** Marks a peer's key line in `authorized_keys`, followed by the peer's Node id. */
    public const string KeyPrefix = 'orbit-task-image-';

    /** Holds the image exports of the host as a source and the receiver's staging files. Root only. */
    public const string WorkDirectory = '/var/lib/orbit-task-vm-image';

    /** The client directory of the Incus API copy that came before. */
    private const string LegacyClientDirectory = '/etc/orbit/task-vms/incus';

    /** The Incus API port of the copy that came before. */
    private const string LegacyIncusPort = '8443';

    private const string HostKeyPattern = '/\A(ssh-ed25519 [A-Za-z0-9+\/]{68})(?: [^\n]*)?\n?\z/D';

    /** Creates the key pair once, and prints its public key. */
    private const string Identity = <<<'BASH'
        install -d -m 0700 -o root -g root -- "$1"
        if [ ! -s "$1/id_ed25519" ] || [ ! -s "$1/id_ed25519.pub" ]; then
            rm -f -- "$1/id_ed25519" "$1/id_ed25519.pub"
            ssh-keygen -q -t ed25519 -N '' -C "$2" -f "$1/id_ed25519"
        fi
        cat -- "$1/id_ed25519.pub"
        BASH;

    /** Installs the receiver from stdin at `$1` when it differs. */
    private const string InstallReceiver = <<<'BASH'
        next=$(mktemp)
        trap 'rm -f -- "$next"' EXIT
        cat >"$next"
        if ! cmp -s -- "$next" "$1"; then
            install -D -m 0755 -o root -g root -- "$next" "$1"
        fi
        BASH;

    /**
     * Rewrites the `authorized_keys` of user `$1`: drops each line whose last field matches the pattern `$2`, keeps
     * every other line, and appends the lines on stdin. An unchanged file is not written.
     */
    private const string AuthorizedKeys = <<<'BASH'
        user=$1
        drop=$2
        home=$(getent passwd -- "$user" | cut -d : -f 6)
        group=$(id -gn -- "$user")
        if [ -z "$home" ] || [ ! -d "$home" ]; then
            echo "the user $user has no home directory" >&2
            exit 1
        fi
        [ -d "$home/.ssh" ] || install -d -m 0700 -o "$user" -g "$group" -- "$home/.ssh"
        file=$home/.ssh/authorized_keys
        if [ -L "$file" ] || { [ -e "$file" ] && [ ! -f "$file" ]; }; then
            echo "$file is not a regular file" >&2
            exit 1
        fi
        next=$(mktemp -- "$home/.ssh/.authorized_keys.XXXXXX")
        trap 'rm -f -- "$next"' EXIT
        { if [ -f "$file" ]; then awk -v drop="$drop" '$NF !~ drop' "$file"; fi; cat; } >"$next"
        if [ -f "$file" ] && cmp -s -- "$next" "$file"; then
            exit 0
        fi
        chown -- "$user:$group" "$next"
        chmod 0600 -- "$next"
        mv -fT -- "$next" "$file"
        BASH;

    /**
     * Sends the file `$1` to the receiver of `$5@$6` with the key `$2`, from the address `$3`, and pins the
     * target's host key `$4`. Prints what the receiver prints.
     */
    private const string Send = <<<'BASH'
        input=$1
        key=$2
        bind=$3
        hostkey=$4
        user=$5
        address=$6
        known=$(mktemp)
        trap 'rm -f -- "$known"' EXIT
        printf '%s %s\n' "$address" "$hostkey" >"$known"
        ssh -F /dev/null -T -i "$key" -b "$bind" -l "$user" -p 22 \
            -o BatchMode=yes -o IdentitiesOnly=yes -o IdentityAgent=none \
            -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known" -o GlobalKnownHostsFile=/dev/null \
            -o HostKeyAlgorithms=ssh-ed25519 -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 \
            -o LogLevel=ERROR -- "$address" <"$input"
        BASH;

    public function __construct(
        private TaskVmSettings $settings,
        private IncusHost $incus,
        private NativeNodeRoleFirewallManager $firewall,
    ) {}

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
     * The addresses an image travels between: the LAN addresses of both hosts when they are in one Cluster and both
     * have one, else their WireGuard addresses.
     *
     * @return array{string, string} the source address and the target address
     */
    public static function route(Node $source, Node $target): array
    {
        $lan = $source->cluster_id !== null && $source->cluster_id === $target->cluster_id
            && self::lanAddress($source) !== null && self::lanAddress($target) !== null;

        return $lan
            ? [(string) $source->lan_ip, (string) $target->lan_ip]
            : [self::wireguard($source), self::wireguard($target)];
    }

    /**
     * Makes key pairs, receivers, and peer keys exact on every member, after it removes what the Incus API copy
     * left. Then each member sends an empty probe to each peer, so a host that cannot reach a peer fails here, not
     * in the next sync. Stops at the first host that fails: what it changed on earlier hosts is valid on its own.
     *
     * @param  (Closure(string): void)|null  $progress  hears one line for each host that is done and each probe
     */
    public function converge(?Closure $progress = null): void
    {
        $members = $this->members();
        $keys = [];
        foreach ($members as [, $node]) {
            $this->withdrawIncusApi($node);
            // A lone host needs no key: it only removes what former peers left.
            if (count($members) > 1) {
                $keys[$node->id] = $this->identity($node);
            }
        }
        foreach ($members as [$host, $node]) {
            $peers = self::peersOf($node, $members);
            if ($peers !== []) {
                $this->installReceiver($node);
            }
            $lines = array_map(fn (Node $peer): string => $this->keyLine($peer, $node, $host->project, $keys[$peer->id]), $peers);
            $this->authorize($node, '^'.self::KeyPrefix.'[0-9]+$', $lines);
            if ($progress instanceof Closure) {
                $progress("Node [{$node->name}] accepts base images from ".($peers === [] ? 'no other host.' : count($peers).' other hosts.'));
            }
        }
        foreach ($members as [, $node]) {
            foreach (self::peersOf($node, $members) as $peer) {
                $this->probe($node, $peer);
                if ($progress instanceof Closure) {
                    $progress("Node [{$node->name}] reaches Node [{$peer->name}] on ".self::route($node, $peer)[1].'.');
                }
            }
        }
    }

    /**
     * Removes the image transfer setup of a Node that is no longer a configured host. First it converges the
     * remaining hosts, which removes the Node's key from each of them. Then it removes the Node's own key pair,
     * receiver, work directory, and peer keys, and what the Incus API copy left on it. A Node record that is gone
     * only converges.
     */
    public function retire(int $nodeId): void
    {
        if ($this->settings->hasHost($nodeId)) {
            throw new TaskVmException('task_vm.host_configured', "Node [{$nodeId}] is still in task_vms.incus.hosts. Remove its entry first.");
        }
        $live = TaskVm::query()->live()->where('host_node_id', $nodeId)->count();
        if ($live > 0) {
            throw new TaskVmException('task_vm.host_in_use', "Node [{$nodeId}] still has {$live} task VMs that are not destroyed, warm ones included. Destroy needs its entry: put it back with warm_vms 0, wait until they are destroyed, then remove it.", 409);
        }
        $this->converge();
        $node = Node::query()->find($nodeId);
        if ($node instanceof Node) {
            $this->withdrawIncusApi($node);
            $this->authorize($node, '^'.self::KeyPrefix.'[0-9]+$', []);
            $this->checked($node, 'remove the transfer key and receiver', $this->incus->root($node, [
                'rm', '-rf', '--', self::KeyDirectory, self::Receiver, self::WorkDirectory,
            ]));
        }
    }

    /**
     * Removes the key of `$nodeId` from every member, so the Node can no longer send images. Node removal runs it
     * for a Node whose host entry is gone, also when `task-vms:retire-host` did not run.
     */
    public function forget(int $nodeId): void
    {
        foreach ($this->members() as [, $node]) {
            if ($node->id !== $nodeId) {
                $this->authorize($node, '^'.self::KeyPrefix.$nodeId.'$', []);
            }
        }
    }

    /**
     * Sends the file `$input` on `$source` to the receiver of `$target`. The Gateway only starts the command: the
     * bytes go from host to host.
     */
    public function send(Node $source, Node $target, string $input, float $timeout): CommandResult
    {
        [$from, $to] = self::route($source, $target);

        return $this->incus->root($source, [
            'bash', '-ceu', self::Send, '--', $input, self::KeyDirectory.'/id_ed25519', $from, $this->hostKey($target), $target->user, $to,
        ], timeout: $timeout);
    }

    /** The authorized_keys line that lets `$peer` send images to `$node`, and nothing else. */
    public function keyLine(Node $peer, Node $node, string $project, string $publicKey): string
    {
        $command = 'sudo -n '.self::Receiver.' '.$project;

        return 'from="'.self::route($peer, $node)[0].'",restrict,command="'.$command.'" '.$publicKey.' '.self::KeyPrefix.$peer->id;
    }

    /** Sends empty input, which the receiver answers with `ready`. */
    private function probe(Node $source, Node $target): void
    {
        $result = $this->send($source, $target, '/dev/null', 60.0);
        if (! $result->succeeded() || trim($result->stdout) !== 'ready') {
            throw self::failed("Node [{$source->name}] cannot reach the image receiver of Node [{$target->name}] on ".self::route($source, $target)[1], $result);
        }
    }

    /**
     * Removes what the Incus API copy left on the host. It unsets `core.https_address` only when it names the
     * host's own LAN or WireGuard address on port 8443, which Orbit set. Every step is a no-op when it is done.
     */
    private function withdrawIncusApi(Node $node): void
    {
        $current = trim($this->checked($node, 'incus config get core.https_address', $this->incus->root($node, ['incus', 'config', 'get', 'core.https_address']))->stdout);
        $owned = array_map(static fn (string $own): string => $own.':'.self::LegacyIncusPort, array_filter([$node->lan_ip, $node->wireguard_ip], is_string(...)));
        if ($current !== '' && in_array($current, $owned, true)) {
            $this->checked($node, 'incus config unset core.https_address', $this->incus->root($node, ['incus', 'config', 'unset', 'core.https_address']));
        }
        $subject = "the trust store of Node [{$node->name}]";
        foreach (IncusHost::json($subject, $this->checked($node, 'incus config trust list', $this->incus->root($node, ['incus', 'config', 'trust', 'list', '--format', 'json']))) as $entry) {
            $name = is_array($entry) ? $entry['name'] ?? null : null;
            $fingerprint = is_array($entry) ? $entry['fingerprint'] ?? null : null;
            if (! is_string($name) || ! str_starts_with($name, self::KeyPrefix)) {
                continue;
            }
            if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                throw IncusHost::invalid($subject, 'an entry has no fingerprint');
            }
            $this->checked($node, "incus config trust remove {$name}", $this->incus->root($node, ['incus', 'config', 'trust', 'remove', $fingerprint]));
        }
        $this->checked($node, 'remove the Incus client directory', $this->incus->root($node, ['rm', '-rf', '--', self::LegacyClientDirectory]));
        try {
            $this->firewall->removeTaskVmImagePeers($node, $node->user);
        } catch (FirewallOperationException $exception) {
            throw new TaskVmException($exception->errorCode, $exception->getMessage(), 502, $exception);
        }
    }

    /** The host's transfer public key, `ssh-ed25519 <base64>`, made once. */
    private function identity(Node $node): string
    {
        $result = $this->checked($node, 'create the transfer key', $this->incus->root($node, ['bash', '-ceu', self::Identity, '--', self::KeyDirectory, self::KeyPrefix.$node->id]));
        if ($result->truncated || preg_match(self::HostKeyPattern, $result->stdout, $matches) !== 1) {
            throw IncusHost::invalid("the transfer key of Node [{$node->name}]", 'it is not one Ed25519 public key');
        }

        return $matches[1];
    }

    /** The target's Ed25519 host key, `ssh-ed25519 <base64>`, read over the Gateway's own pinned connection. */
    private function hostKey(Node $node): string
    {
        $result = $this->checked($node, 'read the SSH host key', $this->incus->root($node, ['cat', '--', '/etc/ssh/ssh_host_ed25519_key.pub']));
        if ($result->truncated || preg_match(self::HostKeyPattern, $result->stdout, $matches) !== 1) {
            throw IncusHost::invalid("the SSH host key of Node [{$node->name}]", 'it is not one Ed25519 public key');
        }

        return $matches[1];
    }

    private function installReceiver(Node $node): void
    {
        $source = file_get_contents(resource_path('task-vms/image-receive.sh'));
        if (! is_string($source)) {
            throw new TaskVmException('task_vm.setup_unavailable', 'The image receiver script is missing.');
        }
        $this->checked($node, 'install the image receiver', $this->incus->root($node, ['bash', '-ceu', self::InstallReceiver, '--', self::Receiver], $source));
    }

    /**
     * Drops the peer key lines that `$drop` matches from the managed user's `authorized_keys`, and adds `$lines`.
     *
     * @param  list<string>  $lines
     */
    private function authorize(Node $node, string $drop, array $lines): void
    {
        $this->checked($node, 'write the peer keys', $this->incus->root($node, [
            'bash', '-ceu', self::AuthorizedKeys, '--', $node->user, $drop,
        ], implode('', array_map(static fn (string $line): string => $line."\n", $lines))));
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

    /** A failed command, with the end of its stderr. */
    public static function failed(string $subject, CommandResult $result): TaskVmException
    {
        $detail = mb_substr(implode("\n", array_slice(explode("\n", trim($result->stderr)), -5)), 0, 2000);

        return new TaskVmException('task_vm.host_command_failed', "{$subject}: exit code [{$result->exitCode}].".($detail === '' ? '' : "\n{$detail}"), 502);
    }

    private function checked(Node $node, string $operation, CommandResult $result): CommandResult
    {
        return $result->succeeded() ? $result : throw self::failed("`{$operation}` on Node [{$node->name}] failed", $result);
    }
}
