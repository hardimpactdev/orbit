<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeAgentRekeyInput;
use App\Domain\Operations\OperationIdempotency;
use App\Models\Node;
use Illuminate\Console\Command;
use Throwable;

final class RekeyNodeAgentCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-agent-rekey {node : Owning Node ID} {operation : Uncertain configure Operation ID} {--attempt=1 : Explicit recovery request number}';

    #[\Override]
    protected $description = 'Record an agent authentication recovery on the owning Node through official SSH.';

    public function handle(StartOperationAction $operations, NodeAgentRekeyInput $input, OperationIdempotency $identity): int
    {
        $ids = array_map(static fn ($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            [$this->argument('node'), $this->argument('operation'), $this->option('attempt')]);
        [$nodeId, $sourceId, $attempt] = $ids;
        $node = $nodeId === false ? null : Node::query()->find($nodeId);
        if ($nodeId === false || $sourceId === false || $attempt === false || $node === null || ! app(ManagedNodeEligibility::class)->allows($node)) {
            $this->error('Recovery requires positive IDs and the owning active managed Node.');

            return self::FAILURE;
        }
        try {
            $identity->kind = 'node.agent_rekey';
            $identity->key = "node-agent-rekey-{$sourceId}-{$attempt}";
            $identity->fingerprint = hash('sha256', json_encode([$nodeId, $sourceId], JSON_THROW_ON_ERROR));
            $operation = $identity->replay('node.agent_rekey') ?? $operations->execute('node.agent_rekey', 'node', $nodeId, $nodeId,
                ['_agent_rekey' => $input->capture($node, $sourceId)],
                'operator-recovery', null, lockKey: 'node-agent-rekey:node:'.$nodeId);
        } catch (Throwable) {
            $this->error('Recovery was not admitted. Check its source and request identity.');

            return self::FAILURE;
        }
        $this->info("Agent recovery Operation #{$operation->id}. Observe its result before cancelling the uncertain configure.");

        return self::SUCCESS;
    }
}
