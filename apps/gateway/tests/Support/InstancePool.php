<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;

/** Marks a Node as holding the Incus pool `orbit-instances`, as ContainerNodes reads it: its agent reports the Instance bridge's health. */
final class InstancePool
{
    public static function give(Node $node): void
    {
        NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => NodeFactKind::Host, 'subject' => ''], [
            'digest' => str_repeat('b', 64), 'observed_at' => now(), 'received_at' => now(),
            'data' => ['os' => 'linux', 'arch' => 'x86_64', 'filesystems' => [], 'instance_bridge' => ['error_code' => null]],
        ]);
    }
}
