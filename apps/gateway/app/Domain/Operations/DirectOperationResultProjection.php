<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use LogicException;

/** One status/result transaction with per-kind domain validation and projection. */
final readonly class DirectOperationResultProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract) {}

    public function input(Operation $operation): array
    {
        return $this->hook($operation)->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->direct->assertRun($operation, ValidatedData::string($data['run_id']));
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $raw = ValidatedData::object($data['result'] ?? []);
            $result = $this->hook($operation)->validate($operation, $raw, $status);
            if ($result !== $raw || $this->contract->result($operation, $result) !== $result) {
                throw new ResourceOperationException('operation.result_invalid', 'Only safe domain metadata is accepted.', 422);
            }
            $code = ValidatedData::nullableString($data['error_code'] ?? null);
            if (($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code))
                || ($status === OperationStatus::Succeeded && ($code !== null || isset($result['error_code'])))) {
                throw new ResourceOperationException('operation.result_invalid', 'The status and refusal code disagree.', 422);
            }
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result, 'direct-apply');
            if (! $terminal && $status->terminal()) {
                $this->hook($projected)->complete($projected, match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed($code, $result),
                    default => OperationOutcome::cancelled($result),
                });
            }

            return $projected;
        });
    }

    private function hook(Operation $operation): DirectOperationResultHook
    {
        $definition = $this->direct->definition($operation->kind);
        $class = $definition['result_hook'] ?? null;
        if ($class === null) {
            throw new LogicException('The generic projection requires a result hook.');
        }

        return app($class);
    }
}
