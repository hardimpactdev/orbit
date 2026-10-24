<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;

final readonly class TaskVmHubFilter
{
    public function __construct(private AgentCommandRunner $agents) {}

    /** @param array{range: string, dns: string, gateway: string, pi_port: int, reverb: string, model: string, routers: list<string>} $input */
    public function prepare(Node $node, array $input): void
    {
        try {
            $result = $this->agents->run($node, 'firewall.task-vm-hub', $input);
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode !== 'task_vm.setup_failed') {
                throw $error;
            }
            $reason = is_string($error->details['reason'] ?? null) ? $error->details['reason'] : $error->getMessage();
            throw new TaskVmException('task_vm.setup_failed', "Task VM hub setup failed on Node [{$node->name}]: {$reason}");
        }
        if (($result['ready'] ?? null) !== true) {
            throw new TaskVmException('task_vm.setup_failed', "Task VM hub setup did not confirm readiness on Node [{$node->name}].");
        }
    }
}
