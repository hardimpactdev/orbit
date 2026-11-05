<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Hands the admitted proof to its one Swamp run, and records its result once, from the terminal event of that run: the verdict
 * lines of each command and, when the run ended its VM, the end of the task group. No step calls back. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunProjection implements DirectOperationProjection, DirectOperationTimeout
{
    /** The `report-N` steps of the workflow, and the captures that read them, stop here. */
    public const int MaximumCommands = 8;

    public function __construct(private ProofRunCleanup $cleanup, private TaskGroupEnding $ending) {}

    /** The run's inputs: the provision and the cancel as the workflows `operation-task-provision` and `operation-task-cancel` take them. */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);
        $provision = ValidatedData::object($input['provision']);
        $cancel = ValidatedData::object($input['cancel']);
        $envelope = app(TaskProvisionProjection::class)->input((new Operation)->forceFill([
            'kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $input['group_id'], 'node_id' => $provision['node_id'], 'input' => $provision]));

        return ['intent' => ['existing' => false, 'group_id' => $input['group_id'], 'checkout_path' => $input['checkout_path'], 'ssh_execution' => $input['ssh_execution'],
            'vm' => $input['vm'], 'commands' => $input['commands'],
            'provision' => ['node_id' => $provision['node_id'], ...$envelope],
            'cancel' => ['node_id' => $cancel['node_id'], ...TaskVmProviderWorkflowInput::envelope($cancel)]], 'execution_secrets' => []];
    }

    /** A proof the Gateway timed out may leave its VM; the group is cancelled the normal way. */
    public function timedOut(Operation $operation): void
    {
        $this->cleanup->schedule($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $native = ValidatedData::object($data['result'] ?? []);
        $verdict = $this->verdict($operation, $native);

        return DB::transaction(function () use ($operation, $data, $native, $verdict): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return $operation;
            }
            $input = ValidatedData::object($operation->input);
            $group = Task::topLevel()->lockForUpdate()->find(ValidatedData::integer($input['group_id']));
            if (NativeRunResult::stepStatus($native, 'cancel') === 'succeeded') {
                if ($group instanceof Task && $group->status !== TaskGroupStatus::Cancelled) {
                    $this->ending->settle($group, ValidatedData::object($input['cancel']), 'tasks.cancel');
                }
            } else {
                // The run ended before its own cancel step or that step failed: the normal cancel ends the group.
                $this->cleanup->schedule($operation);
            }
            if (in_array($data['status'], ['succeeded', 'failed'], true)) {
                $passed = array_all($verdict['cases'], static fn (array $case): bool => $case['verdict'] === 'passed');
                $data['status'] = $passed ? 'succeeded' : 'failed';
                $data['error_code'] = $passed ? null : ($verdict['ran'] ? 'proof.cases_failed' : 'proof.run_failed');
            }

            return app(DirectOperation::class)->project($operation, $data, ['commit' => $input['commit'],
                'outcome' => ($data['status'] ?? null) === 'succeeded' ? 'passed' : 'failed', 'cases' => $verdict['cases'], 'uncovered' => $input['uncovered'],
                'evidence' => ValidatedData::string($input['checkout_path']).'/.orbit-artifacts/'.ValidatedData::integer($input['group_id']).'/verify', 'receipt_url' => null]);
        });
    }

    /**
     * Every command of the selection with the rows of its cases, from the report of its verify. A command without rows, because
     * the VM or the fleet failed before it, is one unrun row.
     *
     * @param  array<string,mixed>  $native
     * @return array{ran: bool, cases: list<array<string,mixed>>}
     */
    private function verdict(Operation $operation, array $native): array
    {
        $cases = [];
        $ran = false;
        foreach (array_values((array) ValidatedData::object($operation->input)['commands']) as $index => $command) {
            $command = ValidatedData::object($command);
            $report = $native['report_'.$index] ?? '';
            $rows = [];
            foreach (ProofVerdictLines::parse(is_string($report) ? $report : '') as $line) {
                $ran = $ran || $line['verdict'] !== 'unrun';
                $rows[] = ['program' => ValidatedData::string($command['file']).'#'.$line['id'], 'command' => $command['command'], 'case' => $line['id'], ...array_diff_key($line, ['id' => 0])];
            }
            $cases = [...$cases, ...($rows !== [] ? $rows : [['program' => $command['file'], 'command' => $command['command'], 'verdict' => 'unrun', 'seconds' => 0]])];
        }

        return ['ran' => $ran, 'cases' => $cases];
    }
}
