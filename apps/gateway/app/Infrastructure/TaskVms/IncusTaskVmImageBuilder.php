<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmHost;
use App\Models\Node;

/** Typed boot preparation, cleaning, and publication intent for recipe image builds. */
final readonly class IncusTaskVmImageBuilder
{
    public function __construct(
        private IncusHost $incus,
        private TaskVmImageRecipe $recipe,
    ) {}

    public function singleBoot(TaskVmHost $host, Node $node, IncusGuestShell $shell): void
    {
        $guest = $shell->agentTarget();
        $this->recipe->owner($guest);
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
