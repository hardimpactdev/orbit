<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use Carbon\CarbonInterface;

/**
 * The prompt the agent receives for one turn in a task copy: the title, brief and deliverables of the subtask, the turn's clock, then
 * the prompt of the caller. The admission records this text as the admitted prompt. See docs/cli/task/copy-turn.mmd.
 */
final readonly class TaskTurnPrompt
{
    /** The longest prompt a turn accepts, in UTF-16 code units. */
    public const int Limit = 100000;

    /** @param  CarbonInterface  $started  the admission's time: the turn's clock starts there and Orbit cuts it TaskCopyTurn::TurnSeconds later */
    public static function compose(Task $subtask, string $prompt, CarbonInterface $started): string
    {
        $parts = ['Subtask: '.$subtask->title, "Brief:\n".$subtask->brief];
        $deliverables = $subtask->deliverableList();
        if ($deliverables !== []) {
            $parts[] = "Deliverables:\n".implode("\n", array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->line(), $deliverables));
        }
        $started = $started->toImmutable()->utc();
        $parts[] = 'This turn started at '.$started->toIso8601ZuluString().' and Orbit ends it at '
            .$started->addSeconds(TaskCopyTurn::TurnSeconds)->toIso8601ZuluString().'. Commit your work before then.';
        $parts[] = "Prompt of this turn:\n".$prompt;
        $text = implode("\n\n", $parts);
        if (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > self::Limit) {
            throw new ResourceOperationException('tasks.prompt_too_large', 'The subtask and the prompt together are longer than a turn accepts ('.self::Limit.' characters).', 422);
        }

        return $text;
    }
}
