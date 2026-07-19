<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\RoleBaselines\VpnRoleBaseline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:converge-vpn-dns-runtime {node : Gateway node with the active VPN role}')]
#[Description('Converge the existing VPN and DNS runtime through its role installer')]
class ConvergeVpnDnsRuntimeCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(VpnRoleBaseline $vpnRoleBaseline): int
    {
        $name = $this->argument('node');

        if (! is_string($name) || trim($name) === '') {
            throw new RuntimeException('A gateway node name is required.');
        }

        $node = Node::query()
            ->where('name', trim($name))
            ->first();

        if (! $node instanceof Node) {
            throw new RuntimeException("Node [{$name}] was not found.");
        }

        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::Vpn->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->first();

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new RuntimeException("Node [{$node->name}] does not have an active VPN role.");
        }

        $vpnRoleBaseline->converge($node, $assignment);

        return self::SUCCESS;
    }
}
