<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class HorizonOperation implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $terminal = $operation->refresh()->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()) {
                Process::query()->whereKey($operation->input['process_id'])->update([
                    'status' => $projected->status === OperationStatus::Succeeded ? LifecycleStatus::Active : LifecycleStatus::Failed,
                    'failed_step' => null, 'error_code' => $projected->error_code,
                    ...($projected->status === OperationStatus::Succeeded ? ['systemd_activation_hash' => $operation->input['content_hash'], 'systemd_activation_pending' => false] : []),
                ]);
            }

            return $projected;
        });
    }
}
