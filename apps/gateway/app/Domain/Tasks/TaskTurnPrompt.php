<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Task;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The prompt the agent receives for one turn in a task copy: the title and brief of the group, the title, brief and deliverables of the
 * subtask, Orbit's copy rules, the turn's clock, then the prompt of the caller. The admission records this text as the admitted prompt.
 * See docs/cli/task/copy-turn.mmd.
 */
final readonly class TaskTurnPrompt
{
    /** The longest prompt a turn accepts, in UTF-16 code units. */
    public const int Limit = 100000;

    /** How an agent works in a task copy, for every role. Project-neutral: it names no command of one Project. */
    public const string CopyRules = "Copy rules:\n"
        ."- Build and prove the feature end to end in this copy, with its nested fleet when it has one, before its pull request: change, run, fix the next layer and repeat until the real end result works here. A release or a live window only confirms a working feature; it never finds its bugs.\n"
        ."- Run every command in the foreground. Leave none running when this turn ends.\n"
        ."- The Project's long gates and full test sets are the work of the task check and CI (`task:check`). Run single test files by path.\n"
        ."- Keep each new or changed test unit within the per-unit time cap of the Project's CI, which the task check prints.\n"
        ."- Commit your work before this turn ends.\n"
        .'- Never print an environment file, a token, a key or a session value.';

    /** @param  CarbonInterface  $started  the admission's time: the turn's clock starts there and Orbit cuts it TaskTurn::TurnSeconds later */
    public static function compose(Task $subtask, string $prompt, CarbonInterface $started): string
    {
        $group = $subtask->parent ?? throw new LogicException('A turn prompt needs the group of its subtask.');
        $parts = ['Task group: '.$group->title, "Group brief:\n".$group->brief, 'Subtask: '.$subtask->title, "Brief:\n".$subtask->brief];
        $deliverables = $subtask->deliverableList();
        if ($deliverables !== []) {
            $parts[] = "Deliverables:\n".implode("\n", array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->line(), $deliverables));
        }
        $parts[] = self::CopyRules;
        $started = $started->toImmutable()->utc();
        $parts[] = 'This turn started at '.$started->toIso8601ZuluString().' and Orbit ends it at '
            .$started->addSeconds(TaskTurn::TurnSeconds)->toIso8601ZuluString().'. Commit your work before then.';
        $parts[] = "Prompt of this turn:\n".$prompt;
        $text = implode("\n\n", $parts);
        if (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > self::Limit) {
            throw new ResourceOperationException('tasks.prompt_too_large', 'The group, the subtask and the prompt together are longer than a turn accepts ('.self::Limit.' characters).', 422);
        }

        return $text;
    }
}
