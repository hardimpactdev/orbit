<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationLateResult;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class ToolConvergeProjection implements DirectOperationLateResult, DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ToolConvergeObservations $observations) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'] ?? []];
    }

    /**
     * A report read after the timeout counts as on time (ToolConvergeObservations::settle); without a readable report, the run's own
     * history says whether its Tools step ran.
     */
    public function late(Operation $operation, string $status, array $result, array $run): void
    {
        $intent = ValidatedData::object($operation->input['intent']);
        $plans = $this->plans($intent);
        $phase = ($result['succeeded'] ?? null) === true ? 'completed' : ToolConvergeObservations::phaseFromRun($run);
        $this->observations->settle($operation, $plans, $this->observations->lateObservations($result['tools'] ?? [], $plans, $phase), $phase,
            ValidatedData::stringList($intent['orphan_sources'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<int, array<string, mixed>> the admitted plans by Tool
     */
    private function plans(array $intent): array
    {
        $plans = [];
        if (is_array($intent['tools'] ?? null)) {
            foreach ($intent['tools'] as $value) {
                $plan = ValidatedData::object($value);
                $plans[ValidatedData::integer($plan['id'])] = $plan;
            }
        }

        return $plans;
    }

    /** A convergence that never ran wrote nothing to its sources. */
    public function neverRan(Operation $operation): void
    {
        AptSourceTrust::settlePlans(ValidatedData::integer($operation->node_id), array_values($this->plans(ValidatedData::object($operation->input['intent']))), $operation->id, 'unwritten');
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $intent = ValidatedData::object($operation->input['intent']);
        $plans = $this->plans($intent);
        $run = ValidatedData::object($data['native_run'] ?? []);
        // A cancelled or interrupted run, or a report that cannot be used, leaves the sources as its own history shows; the Tools a usable
        // report checkpointed still count.
        $unreported = fn (array $projected, array $tools = []): Operation => DB::transaction(function () use ($operation, $projected, $plans, $run, $tools): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! $locked->status->terminal()) {
                $this->observations->settle($locked, $plans, array_values(array_map(ValidatedData::object(...), $tools)), ToolConvergeObservations::phaseFromRun($run), []);
            }

            return $this->direct->project($locked, ...$projected);
        });
        if (in_array($data['status'] ?? null, ['cancelled', 'interrupted'], true)) {
            return $unreported([$data, []], $this->observations->lateObservations(ValidatedData::object($data['result'] ?? [])['tools'] ?? [], $plans, 'partial'));
        }
        $result = ValidatedData::object($data['result'] ?? []);
        $completed = ($result['succeeded'] ?? null) === true;
        try {
            $tools = $this->observations->validate($result['tools'] ?? [], $plans, $completed);
        } catch (ResourceOperationException $error) {
            return $unreported([[...$data, 'status' => 'failed', 'error_code' => $error->errorCode], []]);
        }
        $changed = max(0, ValidatedData::integer($result['changed'] ?? 0));
        $cleanup = ($result['cleanup_complete'] ?? null) === true;
        $code = ! $completed || ! $cleanup ? 'operation.native_failed' : (in_array('failed', array_column($tools, 'status'), true) ? 'tool.converge_failed' : null);
        $success = $code === null && ($data['status'] ?? null) === 'succeeded';

        return DB::transaction(function () use ($operation, $data, $plans, $tools, $code, $changed, $cleanup, $success, $completed, $intent, $run): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status->terminal()) {
                return $locked;
            }
            $this->observations->settle($locked, $plans, $tools, $completed ? 'completed' : ToolConvergeObservations::phaseFromRun($run), ValidatedData::stringList($intent['orphan_sources'] ?? []));

            return $this->direct->project($locked, [...$data, 'status' => $success ? 'succeeded' : 'failed', 'error_code' => $success ? null : ($code ?? 'operation.native_failed')],
                ['changed' => $changed, 'tools' => $tools, 'cleanup_complete' => $cleanup,
                    'outcome' => $success ? ($changed === 0 ? 'unchanged' : 'applied') : 'failed']);
        });
    }
}
