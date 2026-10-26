<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Models\Node;

/** Typed boot preparation, cleaning, and publication intent for recipe image builds. */
final readonly class IncusTaskVmImageBuilder
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmImageRecipe $recipe,
        private GuestAgentCommandRunner $agents,
    ) {}

    public function singleBoot(TaskVmHost $host, Node $node, IncusGuestShell $shell): void
    {
        $guest = $shell->agentTarget();
        $this->recipe->owner($guest);
        try {
            $result = $this->agents->runGuest($guest, 'image.prepare-incus');
        } catch (AgentCommandRefused $error) {
            if ($error->errorCode !== 'task_vm.image_clean_failed') {
                throw $error;
            }
            throw new TaskVmException('task_vm.image_build_failed', $error->getMessage(), previous: $error);
        }
        if (($result['ready'] ?? null) !== true) {
            throw IncusHost::invalid("the base image build VM [{$shell->vm}]", 'cloud-init ordering was not confirmed');
        }
        $this->incus->image($host, $node, ['kind' => 'prepare_metadata', 'name' => $shell->vm]);
    }

    public function clean(IncusGuestShell $shell): void
    {
        $this->recipe->clean($shell->agentTarget());
    }

    /** @param array<string, string> $properties */
    public function publish(TaskVmHost $host, Node $node, string $name, string $alias, array $properties = []): string
    {
        return $this->incus->image($host, $node, ['kind' => 'publish', 'name' => $name, 'alias' => $alias, 'properties' => (object) $properties])
            ?? throw IncusHost::invalid("the base image build VM [{$name}]", 'the published image has no fingerprint');
    }
}
