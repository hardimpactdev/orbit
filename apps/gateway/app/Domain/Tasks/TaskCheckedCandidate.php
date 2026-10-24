<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;

/** The same source-derived hosted-CI gate serves reviewer and publisher admission. */
final readonly class TaskCheckedCandidate
{
    public function __construct(private TaskCheckOutputBuilder $output) {}

    public function eligible(Task $group, TaskCheck $check): bool
    {
        if ($check->kind !== TaskCheckKind::Handoff || $check->status !== TaskCheckStatus::Passed || $check->commit_sha === null) {
            return false;
        }
        $owner = Operation::query()->where('kind', 'tasks.check')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('input->subtask', $check->task_id)->where('input->phase', TaskCheckKind::Handoff->value)->where('state->check_id', $check->id)->first();
        $plan = $owner?->state['ci'] ?? null;
        $result = $owner?->result;
        $requiresCi = $group->project->handoffCheckName() !== null || $plan !== null;
        $ciPassed = ! $requiresCi || (is_array($plan)
            && ($plan['state'] ?? null) === 'passed' && ($plan['fault'] ?? null) === null
            && ($plan['commit'] ?? null) === $check->commit_sha
            && ($plan['branch'] ?? null) === TaskRemoteBranch::handoff($group)
            && is_string($plan['check_name'] ?? null));

        return $owner?->status === OperationStatus::Succeeded && is_array($result)
            && ($result['outcome'] ?? null) === 'passed' && ($result['check_id'] ?? null) === $check->id
            && ($result['commit'] ?? null) === $check->commit_sha
            && $check->head_after !== null && ($result['head'] ?? null) === $check->head_after
            && $check->tree_after !== null && ($result['tree'] ?? null) === $check->tree_after
            && ($result['ci'] ?? null) === $plan && $ciPassed
            && TaskDeliverableVerifier::failures($check->task()->firstOrFail()->deliverableList(), TaskDeliverableEvidence::fromArray($check->deliverable_evidence), $this->output) === [];
    }
}
