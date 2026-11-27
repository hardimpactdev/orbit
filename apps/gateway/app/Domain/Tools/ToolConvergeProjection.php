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
        $this->observations->settle($operation, $plans, $this->observations->lateObservations($result['tools'] ?? [], $plans, $phase), self::repositories($result));
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

    /** A convergence that never ran changed nothing. */
    public function neverRan(Operation $operation): void {}

    /**
     * The repository outcomes a report carries, or none when they cannot be used.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{name: string, status: string, error_code: ?string}>
     */
    private static function repositories(array $result): array
    {
        try {
            return AptRepositoryOutcomes::validate($result['repositories'] ?? []);
        } catch (ResourceOperationException) {
            return [];
        }
    }

    /**
     * A cancelled or interrupted run, or a report that cannot be used: the Tools and repositories a usable report checkpointed still count.
     *
     * @param  array<int, array<string, mixed>>  $plans
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array{name: string, status: string, error_code: ?string}>  $repositories
     */
    private function unreported(Operation $operation, array $plans, array $data, array $tools, array $repositories): Operation
    {
        return DB::transaction(function () use ($operation, $data, $plans, $tools, $repositories): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! $locked->status->terminal()) {
                $this->observations->settle($locked, $plans, $tools, $repositories);
            }

            return $this->direct->project($locked, $data, []);
        });
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $intent = ValidatedData::object($operation->input['intent']);
        $plans = $this->plans($intent);
        if (in_array($data['status'] ?? null, ['cancelled', 'interrupted'], true)) {
            $partial = ValidatedData::object($data['result'] ?? []);

            return $this->unreported($operation, $plans, $data, $this->observations->lateObservations($partial['tools'] ?? [], $plans, 'partial'), self::repositories($partial));
        }
        $result = ValidatedData::object($data['result'] ?? []);
        $completed = ($result['succeeded'] ?? null) === true;
        try {
            $tools = $this->observations->validate($result['tools'] ?? [], $plans, $completed);
            $repositories = AptRepositoryOutcomes::validate($result['repositories'] ?? []);
        } catch (ResourceOperationException $error) {
            return $this->unreported($operation, $plans, [...$data, 'status' => 'failed', 'error_code' => $error->errorCode], [], []);
        }
        $changed = max(0, ValidatedData::integer($result['changed'] ?? 0));
        $cleanup = ($result['cleanup_complete'] ?? null) === true;
        $code = ! $completed || ! $cleanup ? 'operation.native_failed'
            : (in_array('failed', [...array_column($tools, 'status'), ...array_column($repositories, 'status')], true) ? 'tool.converge_failed' : null);
        $success = $code === null && ($data['status'] ?? null) === 'succeeded';

        return DB::transaction(function () use ($operation, $data, $plans, $tools, $repositories, $code, $changed, $cleanup, $success): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status->terminal()) {
                return $locked;
            }
            $this->observations->settle($locked, $plans, $tools, $repositories);

            return $this->direct->project($locked, [...$data, 'status' => $success ? 'succeeded' : 'failed', 'error_code' => $success ? null : ($code ?? 'operation.native_failed')],
                ['changed' => $changed, 'tools' => $tools, 'repositories' => $repositories, 'cleanup_complete' => $cleanup,
                    'outcome' => $success ? ($changed === 0 ? 'unchanged' : 'applied') : 'failed']);
        });
    }
}
