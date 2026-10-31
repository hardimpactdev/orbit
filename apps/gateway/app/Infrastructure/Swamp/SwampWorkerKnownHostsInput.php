<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Models\Node;

/** Public Gateway pin snapshot for the sole control worker; performs no host lookup or write. */
final readonly class SwampWorkerKnownHostsInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    /** @return array{control_node_id:209,known_hosts_content:string,digest:string} */
    public function capture(): array
    {
        $nodes = Node::query();
        foreach (['wireguard_ip', 'ssh_host_key_type', 'ssh_host_key'] as $field) {
            $nodes->whereNotNull($field)->where($field, '!=', '');
        }
        $lines = [];
        foreach ($nodes->orderBy('wireguard_ip')->get() as $node) {
            $target = $this->ssh->forNode($node)['target'];
            foreach ($target['host_keys'] as $key) {
                $lines[] = $target['host'].' '.$key;
            }
        }
        $content = $lines === [] ? '' : implode("\n", $lines)."\n";

        return ['control_node_id' => 209, 'known_hosts_content' => $content, 'digest' => hash('sha256', $content)];
    }
}
