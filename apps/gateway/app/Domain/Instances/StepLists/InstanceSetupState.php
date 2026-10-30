<?php

declare(strict_types=1);

namespace App\Domain\Instances\StepLists;

use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\Operation;

/** The shared Instance projection for a completed setup, without execution policy. */
final readonly class InstanceSetupState
{
    public function project(Operation $operation, OperationStatus $status, ?string $errorCode): void
    {
        if (($operation->input['list'] ?? null) !== 'setup' || ! $status->terminal()) {
            return;
        }
        $instance = Instance::query()->whereKey($operation->target_id)->where('node_id', $operation->node_id)
            ->where('project_id', $operation->input['project_id'] ?? null)
            ->where('checkout_path', $operation->input['execution']['directory'] ?? null)->first();
        if ($instance === null) {
            return;
        }
        $instance->update($status === OperationStatus::Succeeded
            ? ['failed_step' => null, 'error_code' => null, 'first_setup_pending' => false]
            : ['failed_step' => 'setup', 'error_code' => $errorCode]);
    }
}
