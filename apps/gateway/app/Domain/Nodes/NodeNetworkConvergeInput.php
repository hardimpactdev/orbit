<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Support\ValidatedData;

/** Public admission for the direct 209 SSH root, independent of retained parent engines. */
final readonly class NodeNetworkConvergeInput
{
    /** @return array<string,mixed> */
    public function capture(Node $node, bool $rolelessOperator): array
    {
        $network = app(NodeNetworkInput::class)->capturePublic($node, $rolelessOperator);
        $hub = Node::query()->findOrFail(ValidatedData::integer($network['hub_id']));
        if ($hub->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('node.network_unsupported', 'The VPN owner must be active.', 422);
        }
        $ssh = app(SwampSshExecutionInput::class);
        $trust = app(NodeFirewallRuleCatalog::class)->forNode($node)[1];

        return ['ssh' => $ssh->forNode($node), 'hub_ssh' => $ssh->forNode($hub),
            'hub_id' => $hub->id, 'existing_key' => $node->wireguard_public_key,
            'peer' => $network['peer'], 'trust_argv' => ['ufw', ...array_slice($trust->arguments, 2)],
            'other_public_keys' => array_values(Node::query()->whereKeyNot($node->id)
                ->whereNotNull('wireguard_public_key')->pluck('wireguard_public_key')->all()),
        ];
    }
}
