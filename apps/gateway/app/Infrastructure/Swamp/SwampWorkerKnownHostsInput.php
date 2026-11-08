<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Nodes\SwampNode;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * Public Gateway pin snapshot for the sole control worker; performs no host lookup or write. A task VM's Node is no part of it: every run
 * that reaches a task VM carries its pinned key in its intent, and the Node lives for one task start only (docs/cli/task/start.mmd).
 */
final readonly class SwampWorkerKnownHostsInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    /** Whether a change of `$node`'s pin changes the snapshot. */
    public static function covers(Node $node): bool
    {
        return ! TaskVm::query()->where('node_id', $node->id)->exists();
    }

    /** @return array{control_node_id:int,known_hosts_content:string,digest:string} */
    public function capture(): array
    {
        $nodes = Node::query()->whereNotIn('id', TaskVm::query()->whereNotNull('node_id')->select('node_id'));
        foreach (['wireguard_ip', 'ssh_host_key_type', 'ssh_host_key'] as $field) {
            $nodes->whereNotNull($field)->where($field, '!=', '');
        }
        $lines = [];
        foreach ($nodes->orderBy('wireguard_ip')->get() as $node) {
            $target = $this->ssh->forNode($node)['target'];
            // Every Node selected here has a recorded key, so its target is never left unpinned.
            foreach ($target['host_keys'] ?? [] as $key) {
                $lines[] = $target['host'].' '.$key;
            }
        }
        $content = $lines === [] ? '' : implode("\n", $lines)."\n";

        return ['control_node_id' => SwampNode::id(), 'known_hosts_content' => $content, 'digest' => hash('sha256', $content)];
    }
}
