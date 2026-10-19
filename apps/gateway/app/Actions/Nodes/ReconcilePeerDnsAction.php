<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ReconcilePeerDnsAction
{
    public function __construct(private PeerDnsPolicy $policy, private PeerDnsInspector $inspector, private RepairNodeDnsAction $repair) {}

    /** @return list<string> Failed Node names. */
    public function execute(): array
    {
        $this->policy->domains();
        $failed = [];
        foreach (Node::query()->with('roles')->orderBy('id')->get() as $node) {
            if (! $this->policy->manages($node)) {
                continue;
            }
            try {
                if (! $this->inspector->inspect($node)['policy']) {
                    $this->repair->execute($node->name);
                }
            } catch (Throwable $exception) {
                $failed[] = $node->name;
                Log::warning('Peer DNS reconciliation failed.', ['node_id' => $node->id, 'exception' => $exception::class]);
            }
        }

        return $failed;
    }
}
