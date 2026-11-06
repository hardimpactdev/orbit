<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeSshAccessProjection;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Adds Orbit's SSH keys to a Node's managed account: the swamp role key, then operator keys Orbit records (none yet). */
final readonly class AdmitNodeSshAccessAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private SwampSshExecutionInput $ssh) {}

    public function execute(Node $node, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.ssh_access_converge')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $intent = $this->intent($node);

            return $this->existing($node, $intent) ?? $this->operations->execute('node.ssh_access_converge', 'node', $node->id, $node->id,
                ['intent' => $intent, 'execution_secrets' => [],
                    'participant_node_ids' => array_values(array_unique([SwampNode::id(), $node->id]))], 'operator', $requestId);
        });
    }

    /** @return array<string,mixed> The SSH target and Orbit's keys: the swamp role key, then operator keys Orbit records (none yet). */
    private function intent(Node $node): array
    {
        $intent = $this->ssh->forNode($node);
        if ($intent['target']['user'] === 'root') {
            throw new ResourceOperationException('node.ssh_account_invalid', 'SSH access publication requires the managed non-root account.', 422);
        }
        $intent['keys'] = [$intent['public_key']];
        $intent['digest'] = NodeSshAccessProjection::digest($intent);

        return $intent;
    }

    /** @param array<string,mixed> $intent */
    private function existing(Node $node, array $intent): ?Operation
    {
        $existing = Operation::query()->where('kind', 'node.ssh_access_converge')->where('target_type', 'node')->where('target_id', $node->id)
            ->where('input->intent->digest', $intent['digest'])->whereIn('status', ['queued', 'running', 'succeeded'])->latest('id')->first();

        return $existing !== null && (! $existing->status->terminal() || ($existing->result['ssh_access_ready'] ?? false) === true) ? $existing : null;
    }
}
