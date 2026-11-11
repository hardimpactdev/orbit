<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Projection for the bounded log workflow declaration. Live container state comes only from the Node observer. */
final readonly class ContainerProcessObservation implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        if ($operation->target_type !== 'process' || ! Process::query()->whereKey($operation->target_id)->exists()) {
            throw new ResourceOperationException('process.target_unavailable', 'The container Process is gone.', 409);
        }

        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $state = $status === OperationStatus::Succeeded
                ? ValidatedData::string(ValidatedData::object($data['result'] ?? [])['state'] ?? 'unknown') : 'unknown';
            $projected = app(DirectOperation::class)->project($operation, $data, ['state' => $state]);

            return $projected;
        });
    }
}
