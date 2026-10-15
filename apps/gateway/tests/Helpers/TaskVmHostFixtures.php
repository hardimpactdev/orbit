<?php

declare(strict_types=1);

use App\Models\Node;

/** @param list<array<string, mixed>> $hosts */
function task_vm_node_hosts(array $hosts): void
{
    foreach (Node::query()->get() as $node) {
        $settings = $node->settings ?? [];
        unset($settings['task_vms']);
        $node->update(['settings' => $settings === [] ? null : $settings]);
    }
    foreach ($hosts as $entry) {
        $id = $entry['node_id'];
        unset($entry['node_id']);
        $node = Node::query()->find($id) ?? Node::query()->forceCreate([
            'id' => $id, 'name' => 'task-host-'.$id, 'status' => 'active',
            'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit',
            'public_ssh_host' => '192.0.2.'.$id,
        ]);
        $node->update(['settings' => [...($node->settings ?? []), 'task_vms' => $entry]]);
    }
}
