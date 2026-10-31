<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Safe admitted input and bounded Process domain projection only. */
final readonly class ProcessControlProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private ProcessOperationCompletion $completion) {}

    public function input(Operation $operation): array
    {
        $input = Arr::only($operation->input, ['process_id', 'agent_sha256', 'runtime', 'name', 'on_demand', 'directory', 'vite']);
        if (in_array($operation->kind, ['process.start', 'process.restart'], true) && isset($operation->input['vite_plan'])) {
            $input['vite_plan'] = $operation->input['vite_plan'];
            $input['caddy_publication'] = CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id));
        }

        return $input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $reported = ValidatedData::object($data['result'] ?? []);
        $result = match ($status) {
            OperationStatus::Succeeded => ['ready' => true, ...($operation->kind === 'process.destroy' ? [] : ['state' => $reported['state'] ?? null])],
            OperationStatus::Failed => ['error_code' => $code],
            default => [],
        };
        if (($status === OperationStatus::Succeeded && ($reported['ready'] ?? null) !== true)
            || ($status === OperationStatus::Failed && ($code === null || ($reported['error_code'] ?? null) !== $code))
            || ($status !== OperationStatus::Failed && $code !== null)
            || ($status === OperationStatus::Succeeded && $operation->kind !== 'process.destroy'
                && (! is_string($reported['state'] ?? null) || preg_match('/\A[a-z][a-z_-]{0,63}\z/D', $reported['state']) !== 1))) {
            throw new ResourceOperationException('operation.result_invalid', 'The Process needs a bounded native outcome.', 422);
        }

        return DB::transaction(function () use ($operation, $data, $status, $code, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'process-control');
            if (! $terminal && $status->terminal()) {
                $outcome = match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed(ValidatedData::string($code), $result),
                    OperationStatus::Cancelled => OperationOutcome::cancelled(),
                    default => throw new \LogicException('A terminal Process status is required.'),
                };
                $this->completion->complete($projected, $outcome);
            }

            return $projected;
        });
    }
}
