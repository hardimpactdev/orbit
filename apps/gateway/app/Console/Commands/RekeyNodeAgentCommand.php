<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationIdempotency;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use Illuminate\Console\Command;
use Throwable;

final class RekeyNodeAgentCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-agent-rekey {node : Node ID} {--attempt=1 : Request number; a higher number makes a new key again}';

    #[\Override]
    protected $description = 'Make a new Node agent key on the Node through official SSH; only its verifier is recorded.';

    public function handle(StartOperationAction $operations, OperationIdempotency $identity, SwampSshExecutionInput $ssh): int
    {
        [$nodeId, $attempt] = array_map(static fn ($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            [$this->argument('node'), $this->option('attempt')]);
        $node = $nodeId === false ? null : Node::query()->find($nodeId);
        if ($nodeId === false || $attempt === false || $node === null || ! app(ManagedNodeEligibility::class)->allows($node)) {
            $this->error('A rekey requires a positive attempt and an active, managed Linux Node.');

            return self::FAILURE;
        }
        try {
            $identity->kind = 'node.agent_rekey';
            $identity->key = "node-agent-rekey-{$nodeId}-{$attempt}";
            $identity->fingerprint = hash('sha256', json_encode([$nodeId, $attempt], JSON_THROW_ON_ERROR));
            // Converge and rekey share one lock per Node.
            $operation = $identity->replay('node.agent_rekey') ?? $operations->execute('node.agent_rekey', 'node', $nodeId, $nodeId,
                ['_agent_rekey' => ['node_id' => $nodeId, 'ssh' => $ssh->forNode($node)]],
                'operator-recovery', null, lockKey: 'node-converge:node:'.$nodeId);
        } catch (Throwable) {
            $this->error('The rekey was not admitted. Check the Node and the request number.');

            return self::FAILURE;
        }
        $this->info("Agent rekey Operation #{$operation->id}. Follow it with operation:show.");

        return self::SUCCESS;
    }
}
