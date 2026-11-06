<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\SwampNode;
use App\Models\Node;

/** A test world has the Node that Swamp executes from, as production has: the Node that holds the swamp role. */
final class SwampNodeFixture
{
    public const int Id = 209;

    public static function ensure(): Node
    {
        $existing = SwampNode::idOrNull();
        if ($existing !== null) {
            return Node::query()->findOrFail($existing);
        }
        $node = Node::query()->find(self::Id) ?? Node::withoutEvents(static fn (): Node => Node::query()->forceCreate([
            'id' => self::Id, 'name' => 'swamp-fixture', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
            'ssh_host_fingerprint' => 'SHA256:swamp-fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'swamp-fixture-key',
        ]));
        $node->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);

        return $node;
    }
}
