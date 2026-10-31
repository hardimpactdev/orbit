<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class MetricsCredentialResetProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private MetricsCredentialResetMaterial $material) {}

    public function input(Operation $operation): array
    {
        return array_intersect_key($operation->input, array_flip(['assignment_id', 'reset_revision', 'address']));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $terminal = $operation->status->terminal();
            $done = $this->direct->project($operation, $data, ValidatedData::object($data['result'] ?? []));
            if (! $terminal && $done->status === OperationStatus::Succeeded) {
                $this->material->promote($done);
            }

            return $done;
        });
    }
}
