<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
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
 * The scripts are in `resources/task-vms`: `peer-identity.sh`, `peer-install-receiver.sh`, `peer-authorized-keys.sh`,
 * and `peer-send.sh`.
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

    private const string HostKeyPattern = '/\A(ssh-ed25519 [A-Za-z0-9+\/]{68})(?: [^\n]*)?\n?\z/D';

    public function __construct(
        private TaskVmSettings $settings,
        private IncusHost $incus,
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
     * Makes key pairs, receivers, and peer keys exact on every member. Then each member sends an empty probe to each
     * peer, so a host that cannot reach a peer fails here, not in the next sync. Stops at the first host that fails: what it changed on earlier hosts is valid on its own.
     *
     * @param  (Closure(string): void)|null  $progress  hears one line for each host that is done and each probe
     */
    public function converge(?Closure $progress = null): void
    {
        $members = $this->members();
        $keys = [];
        // A lone host needs no key: it only removes what former peers left.
        if (count($members) > 1) {
            foreach ($members as [, $node]) {
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
     * receiver, work directory, and peer keys. A Node record that is gone only converges.
     */
    public function retire(int $nodeId): void
    {
        if ($this->settings->hasHost($nodeId)) {
            throw new TaskVmException('task_vm.host_configured', "Node [{$nodeId}] still has settings.task_vms. Clear it first.");
        }
        $live = TaskVm::query()->live()->where('host_node_id', $nodeId)->count();
        if ($live > 0) {
            throw new TaskVmException('task_vm.host_in_use', "Node [{$nodeId}] still has {$live} task VMs that are not destroyed, warm ones included. Destroy needs its entry: put it back with warm_vms 0, wait until they are destroyed, then remove it.", 409);
        }
        $this->converge();
        $node = Node::query()->find($nodeId);
        if ($node instanceof Node) {
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

        return $this->incus->root($source, TaskVmScripts::bash('peer-send.sh', [
            $input, self::KeyDirectory.'/id_ed25519', $from, $this->hostKey($target), $target->user, $to,
        ]), timeout: $timeout);
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

    /** The host's transfer public key, `ssh-ed25519 <base64>`, made once. */
    private function identity(Node $node): string
    {
        $result = $this->checked($node, 'create the transfer key', $this->incus->root($node, TaskVmScripts::bash('peer-identity.sh', [self::KeyDirectory, self::KeyPrefix.$node->id])));
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
        $this->checked($node, 'install the image receiver', $this->incus->root($node, TaskVmScripts::bash('peer-install-receiver.sh', [self::Receiver]), TaskVmScripts::read('image-receive.sh')));
    }

    /**
     * Drops the peer key lines that `$drop` matches from the managed user's `authorized_keys`, and adds `$lines`.
     *
     * @param  list<string>  $lines
     */
    private function authorize(Node $node, string $drop, array $lines): void
    {
        $this->checked($node, 'write the peer keys', $this->incus->root($node, TaskVmScripts::bash('peer-authorized-keys.sh', [$node->user, $drop]), implode('', array_map(static fn (string $line): string => $line."\n", $lines))));
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
