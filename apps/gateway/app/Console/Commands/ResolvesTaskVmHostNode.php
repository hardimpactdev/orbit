<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Models\Node;

/** Resolves the Node argument of the task VM host commands by id or name. */
trait ResolvesTaskVmHostNode
{
    private function activeNode(string $reference): Node
    {
        $node = ctype_digit($reference)
            ? Node::query()->find((int) $reference)
            : Node::query()->where('name', $reference)->first();

        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
            throw new TaskVmException('task_vm.unknown_host', "Node [{$reference}] is not an active Node.");
        }

        return $node;
    }
}
