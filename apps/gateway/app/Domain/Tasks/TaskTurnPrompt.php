<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;

/**
 * The prompt a task VM receives for one turn: the title, brief and deliverables of the subtask, then the prompt of the caller.
 * The admission records this text as the admitted prompt. See docs/cli/tasks/turn.mmd.
 */
final readonly class TaskTurnPrompt
{
    public static function compose(Task $subtask, string $prompt): string
    {
        $parts = ['Subtask: '.$subtask->title, "Brief:\n".$subtask->brief];
        $deliverables = $subtask->deliverableList();
        if ($deliverables !== []) {
            $parts[] = "Deliverables:\n".implode("\n", array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->line(), $deliverables));
        }
        $parts[] = "Prompt of this turn:\n".$prompt;

        return implode("\n\n", $parts);
    }
}
