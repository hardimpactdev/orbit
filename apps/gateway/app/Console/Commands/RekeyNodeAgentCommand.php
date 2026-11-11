<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Console\Command;
use Throwable;

final class RekeyNodeAgentCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-agent-rekey {node : Node ID} {--attempt=1 : Request number; a higher number makes a new key again}';

    #[\Override]
    protected $description = 'Write a new Node agent key on the Node through the vault and official SSH; only its verifier is recorded.';

    public function handle(StartOperationAction $operations, OperationIdempotency $identity, SwampSshExecutionInput $ssh,
        SwampCommandStep $steps, SwampScheduleWorkflows $vault): int
    {
        [$nodeId, $attempt] = array_map(static fn ($value) => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            [$this->argument('node'), $this->option('attempt')]);
        $node = $nodeId === false ? null : Node::query()->find($nodeId);
        if ($nodeId === false || $attempt === false || $node === null || ! app(ManagedNodeEligibility::class)->allows($node)) {
            $this->error('A rekey requires a positive attempt and an active, managed Linux Node.');

            return self::FAILURE;
        }
        $key = "node-agent-rekey-{$nodeId}-{$attempt}";
        $copy = null;
        try {
            $operation = $identity->under('node.agent_rekey', $key, hash('sha256', json_encode([$nodeId, $attempt], JSON_THROW_ON_ERROR)),
                function () use ($identity, $operations, $steps, $ssh, $node, $nodeId, $key, &$copy): Operation {
                    $replayed = $identity->replay('node.agent_rekey');
                    if ($replayed !== null) {
                        return $replayed;
                    }
                    // The key reaches the Node only through this vault copy; the task keeps its verifier.
                    $secret = bin2hex(random_bytes(32));
                    $copy = $steps->executionCopy('orbit-control-inputs', $key, $secret);

                    return $operations->execute('node.agent_rekey', 'node', $nodeId, $nodeId,
                        ['_agent_rekey' => ['node_id' => $nodeId, 'ssh' => $ssh->forNode($node), 'verifier_hash' => hash('sha256', $secret), 'agent_key' => $copy]],
                        'operator-recovery', null, lockKey: OperationStore::lockKey('node', $nodeId));
                });
        } catch (Throwable) {
            if ($copy !== null) {
                rescue(fn () => $vault->deleteSecret('orbit-control-inputs', $key), report: false);
            }
            $this->error('The rekey was not admitted. Check the Node and the request number.');

            return self::FAILURE;
        }
        $this->info("Agent rekey Operation #{$operation->id}. Follow it with operation:show.");

        return self::SUCCESS;
    }
}
