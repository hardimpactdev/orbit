<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class ToolConvergeProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ToolConvergeObservations $observations) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if (in_array($data['status'] ?? null, ['cancelled', 'interrupted'], true)) {
            return $this->direct->project($operation, $data, []);
        }
        $result = ValidatedData::object($data['result'] ?? []);
        $intent = ValidatedData::object($operation->input['intent']);
        $plans = [];
        if (is_array($intent['tools'] ?? null)) {
            foreach ($intent['tools'] as $value) {
                $plan = ValidatedData::object($value);
                $plans[ValidatedData::integer($plan['id'])] = $plan;
            }
        }
        $completed = ($result['succeeded'] ?? null) === true;
        try {
            $tools = $this->observations->validate($result['tools'] ?? [], $plans, $completed);
        } catch (ResourceOperationException $error) {
            return $this->direct->project($operation, [...$data, 'status' => 'failed', 'error_code' => $error->errorCode], []);
        }
        $changed = max(0, ValidatedData::integer($result['changed'] ?? 0));
        $cleanup = ($result['cleanup_complete'] ?? null) === true;
        $code = ! $completed || ! $cleanup ? 'operation.native_failed' : (in_array('failed', array_column($tools, 'status'), true) ? 'tool.converge_failed' : null);
        $success = $code === null && ($data['status'] ?? null) === 'succeeded';

        return DB::transaction(function () use ($operation, $data, $plans, $tools, $code, $changed, $cleanup, $success): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status->terminal()) {
                return $locked;
            }
            $this->observations->record(ValidatedData::integer($operation->node_id), $plans, $tools);

            return $this->direct->project($locked, [...$data, 'status' => $success ? 'succeeded' : 'failed', 'error_code' => $success ? null : ($code ?? 'operation.native_failed')],
                ['changed' => $changed, 'tools' => $tools, 'cleanup_complete' => $cleanup,
                    'outcome' => $success ? ($changed === 0 ? 'unchanged' : 'applied') : 'failed']);
        });
    }
}
