<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;

/**
 * The prompt a task VM receives for one turn: the title, brief and deliverables of the subtask, then the prompt of the caller.
 * The admission records this text as the admitted prompt. See docs/cli/tasks/turn.mmd.
 */
final readonly class TaskTurnPrompt
{
    /** The longest prompt `operation-task-turn` accepts, in UTF-16 code units, as its schema counts them. */
    public const int Limit = 100000;

    public static function compose(Task $subtask, string $prompt): string
    {
        $parts = ['Subtask: '.$subtask->title, "Brief:\n".$subtask->brief];
        $deliverables = $subtask->deliverableList();
        if ($deliverables !== []) {
            $parts[] = "Deliverables:\n".implode("\n", array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->line(), $deliverables));
        }
        $parts[] = "Prompt of this turn:\n".$prompt;
        $text = implode("\n\n", $parts);
        if (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > self::Limit) {
            throw new ResourceOperationException('tasks.prompt_too_large', 'The subtask and the prompt together are longer than a turn accepts ('.self::Limit.' characters).', 422);
        }

        return $text;
    }
}
