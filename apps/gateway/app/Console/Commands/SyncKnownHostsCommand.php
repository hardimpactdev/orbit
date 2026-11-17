<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Models\Node;
use Illuminate\Console\Command;

/** Restore local SSH trust from recorded Node pins; never discover or approve a key. */
final class SyncKnownHostsCommand extends Command
{
    #[\Override]
    protected $signature = 'gateway:known-hosts:sync';

    #[\Override]
    protected $description = 'Restore the Gateway SSH known_hosts from recorded Node pins.';

    public function handle(KnownHostsStore $knownHosts): int
    {
        $nodes = Node::query();
        foreach (['wireguard_ip', 'ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint'] as $field) {
            $nodes->whereNotNull($field)->where($field, '!=', '');
        }
        $changed = false;
        foreach ($nodes->orderBy('id')->cursor() as $node) {
            $changed = $knownHosts->put((string) $node->wireguard_ip, 22, new HostKey(
                (string) $node->ssh_host_key_type,
                (string) $node->ssh_host_key,
                (string) $node->ssh_host_fingerprint,
            )) || $changed;
        }
        $this->line(json_encode(['changed' => $changed], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
