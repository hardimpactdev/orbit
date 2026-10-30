<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperationPrimitive;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseExecutionIntent;
use App\Models\Node;
use App\Models\Operation;

/** One fixed cleanup primitive; native Swamp owns ordering and recovery. */
final readonly class DirectDatabaseCleanup implements DirectOperationPrimitive
{
    /** @return array<string, mixed> */
    public function execute(Operation $operation): array
    {
        if ($operation->kind !== 'database.sqlite_cleanup' || $operation->node_id === null) {
            throw new ResourceOperationException('database.cleanup_invalid', 'A Node-bound database cleanup intent is required.', 422);
        }
        $intent = DatabaseExecutionIntent::recorded($operation->input['intent_id'] ?? null);
        $command = $intent->payload['commands']['database-apply'] ?? null;
        if ($intent->target_type !== $operation->target_type || $intent->target_id !== $operation->target_id
            || $intent->node_id !== $operation->node_id || ! is_array($command)
            || count($intent->payload['commands']) !== 1 || ($intent->payload['projection']['children'] ?? []) !== []
            || ! in_array($command['name'], ['instance.copy-sqlite', 'instance.prepare-sqlite-seed'], true)
            || ($command['protected'] ?? []) !== []
            || ($command['name'] === 'instance.copy-sqlite' ? ($command['input']['operation'] ?? null) : ($command['input']['mode'] ?? null)) !== 'cleanup') {
            throw new ResourceOperationException('database.cleanup_invalid', 'The stored cleanup does not match its admitted target.', 422);
        }
        try {
            app(AgentCommandRunner::class)->run(Node::query()->findOrFail($operation->node_id), $command['name'], $command['input']);
        } catch (AgentCommandRefused $exception) {
            return ['error_code' => $exception->errorCode];
        }

        return ['backend' => 'absent'];
    }
}
