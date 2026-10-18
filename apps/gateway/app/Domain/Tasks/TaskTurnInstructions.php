<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;

/**
 * Tells an agent how to end its turn with the turn command.
 */
final readonly class TaskTurnInstructions
{
    /**
     * @param  list<TaskDeliverable>  $deliverables
     * @param  string|null  $check  the Project task check command, or null when the Project has none (ADR 0125)
     */
    public static function implementer(array $deliverables = [], ?string $check = null, ?int $threadId = null): string
    {
        $gate = $check === null ? '' : ' Orbit runs '.$check.' when you hand off.';
        $confirm = $deliverables === [] ? '' : ' Add --deliverable=ID=evidence for each deliverable of this subtask ('.self::ids($deliverables).'), where the evidence says where or how it is met.';

        return self::origin().' '.self::autonomy().$gate.' When the brief is complete, end your turn with '.self::command($threadId, '--outcome=ready_for_review --summary="What you changed"').'.'.$confirm.' '.self::blocked($threadId);
    }

    /**
     * The approval of the last subtask also describes the pull request Orbit opens.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public static function reviewer(bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, bool $delta = false): string
    {
        $cause = $mode?->causeRequired === true ? ' --cause=CAUSE' : '';
        $approve = $final
            ? 'This is the last subtask. When it meets its brief, end your turn with '.self::command($threadId, '--outcome=approved --summary="What you checked" --pr-summary="One or two sentences about the whole feature" --pr-change="A new feature or behavior change" --pr-breaking="A breaking change"'.$cause).'. Repeat --pr-change for each change in the feature, and --pr-breaking for each breaking change, or pass --pr-breaking=none. The change list, summary and breaking list are yours to write: add a missing entry yourself instead of requesting changes.'
            : 'When the subtask meets its brief, end your turn with '.self::command($threadId, '--outcome=approved --summary="What you checked"'.$cause).'.';
        $reviews = array_values(array_filter($deliverables, static fn (TaskDeliverable $deliverable): bool => $deliverable->type === TaskDeliverableType::Review));
        if ($reviews !== []) {
            $approve .= ' The approval must confirm each review deliverable ('.self::ids($reviews).') with --deliverable=ID=evidence, where the evidence says what you checked.';
        }

        return ($delta ? 'This is a delta re-review since the last reviewed tree. Check whether the earlier findings are resolved and whether the delta adds a new blocking defect. Do not re-review the whole branch. ' : '').self::origin().' Leave the workspace tree as you found it: Orbit checks that no tracked or untracked workspace file changed when your turn ends. You may run commands, use sudo, and create disposable files outside the workspace, such as under /tmp. Do not edit or commit the code; Orbit commits after you approve. Block only for: (a) a defect likely in normal operation (ordinary use, routine deploys, common operator mistakes, normal overlap of scheduled jobs); (b) data loss, a security issue, or a state that no supported command can recover; (c) an unmet brief item or deliverable; (d) a regression of existing behaviour; (e) a breach of the quality bar in the repository\'s instructions, such as a fallback, a legacy or compatibility path, or a rule kept in two places. For each blocking finding, give its file:line, the rule it meets, and what must change; for (a), also name the normal-operation trigger. A finding that needs a crash, a lost reply or a timeout between two specific steps is a follow-up unless a deliverable names that guarantee; do not ask for markers, fences or locks against it; an operator recovery command is enough. List follow-ups under \'Follow-ups\' in the approval; they never block. After two changes_requested on one subtask, only (b), (c), (d) and (e) findings may block. '.$approve.' Otherwise end your turn with '.self::command($threadId, '--outcome=changes_requested --summary="The findings the implementer must address"'.$cause).'. '.($cause === '' ? '' : 'This review follows an operator direction. Every review outcome needs --cause. ').self::blocked($threadId, true);
    }

    /** The reviewer answers an implementer's question from the contract, before the operator is asked. */
    public static function consult(?int $threadId = null): string
    {
        return self::origin().' This is a consult, not a review. Answer from the brief, the ADRs, the documentation, the code, and the task history. End your turn with '.self::command($threadId, '--outcome=answered --summary="The answer for the implementer" --cause=CAUSE').', or '.self::command($threadId, '--outcome=blocked --summary="Why the contract cannot answer it" --question="One specific question" --cause=CAUSE').'. '.self::causes();
    }

    /** The reviewer translates an operator's direction before the implementer continues. */
    public static function relay(?int $threadId = null): string
    {
        return self::origin().' This is a relay of the operator\'s direction, not a review. End your turn with '.self::command($threadId, '--outcome=answered --summary="The answer for the implementer" --cause=CAUSE').', or '.self::command($threadId, '--outcome=blocked --summary="Why the contract cannot answer it" --question="One specific question" --cause=CAUSE').'. '.self::causes();
    }

    /**
     * The deliverables as a list for an agent prompt, or an empty string without any.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public static function deliverables(array $deliverables): string
    {
        if ($deliverables === []) {
            return '';
        }

        return "Deliverables. Orbit checks each one before the review:\n".implode("\n", array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->line(), $deliverables));
    }

    /**
     * The workspace commit the group started at, and the stat from there to HEAD.
     * Empty unless the commit is the 40 or 64 hexadecimal characters Orbit records.
     */
    public static function groupStart(string $commit): string
    {
        if (preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            return '';
        }

        return 'The group started at '.$commit.".\n".'git diff --stat '.$commit.'..HEAD';
    }

    /**
     * The subtask's declared topology for an agent prompt, or an empty string without one. A reviewer must prove each
     * deliverable on the declared nodes with recorded evidence ({@see TaskTopologyEvidence}).
     */
    public static function topology(int $groupId, TaskTopology $topology, bool $reviewer): string
    {
        if ($topology->isEmpty()) {
            return '';
        }
        $issue = 'TASK-'.$groupId;
        $nodes = implode(', ', $topology->nodes());
        $text = 'This subtask declares the topology '.implode(', ', $topology->toArray()).'. Orbit prepared it nested in this task VM as '.$issue
            .': a test Gateway, an operator, and the declared nodes with their roles. Use it with bin/e2e-topology, for example `bin/e2e-topology exec '.$issue.' operator --argv=\'["orbit","node:list","--json"]\'`, and follow the using-incus-topologies skill. Never acquire or release it.';

        return $reviewer
            ? $text.' `--record` appends to .e2e/evidence.jsonl, which is not part of the workspace tree. Prove each deliverable on the declared nodes ('.$nodes.') with `bin/e2e-topology exec '.$issue.' NODE --argv=JSON --record=LABEL`, where LABEL names the deliverable, and cite the labels in your --deliverable evidence and summary. Orbit refuses the approval until the evidence log holds a recorded command that did work on each declared node and exited 0, started after the last handoff, and it adds those commands to your approval.'
            : $text.' The reviewer must prove the deliverables on '.$nodes.', so make them work there.';
    }

    private static function origin(): string
    {
        return 'Orbit fetches origin before every agent turn. Do not fetch or push. Orbit publishes the approved commit itself.';
    }

    private static function autonomy(): string
    {
        return 'Work autonomously. You may change or delete disposable fixtures in this task\'s environment and resolve routine test prerequisites yourself. Do not touch live or shared resources or another task\'s fixtures.';
    }

    private static function command(?int $threadId, string $arguments): string
    {
        return '"$(git rev-parse --git-path orbit)/turn" '.($threadId === null ? '' : '--thread='.$threadId.' ').$arguments;
    }

    private static function causes(): string
    {
        return 'CAUSE is one of brief_unclear, contract_gap, scope, environment, or missed_contract.';
    }

    /**
     * An implementer's blocked turn asks the reviewer first. A reviewer's blocked turn asks the operator.
     */
    private static function blocked(?int $threadId, bool $reviewer = false): string
    {
        if ($reviewer) {
            return 'When the brief, the ADRs, the documentation, the code, and the task history cannot answer a question, end your turn with '.self::command($threadId, '--outcome=blocked --summary="What the contract cannot answer" --question="One specific question" --cause=CAUSE').' to ask the operator. '.self::causes();
        }

        return 'Block only when ownership is unclear, the work needs live or shared resources beyond this task, access is missing, or a product decision needs the operator. Then end your turn with '.self::command($threadId, '--outcome=blocked --summary="What stops you and what you tried" --question="One specific question"').'. Your reviewer answers first. If you can decide or find the answer yourself, keep working instead.';
    }

    /** @param list<TaskDeliverable> $deliverables */
    private static function ids(array $deliverables): string
    {
        return implode(', ', array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $deliverables));
    }
}
