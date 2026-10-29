<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;

/** Read-only alerts for admitted work that has no executor. */
final readonly class InstanceDeploymentHealth
{
    public const int UnclaimedAfterMinutes = 5;

    /** @return Builder<Operation> */
    public function unclaimed(): Builder
    {
        return Operation::query()->whereIn('kind', ['instance.deploy', 'instance.rollback'])
            ->whereNotIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])
            ->whereNull('started_at')->where('created_at', '<=', now()->subMinutes(self::UnclaimedAfterMinutes));
    }

    /** @return array{error_code:string}|null */
    public function assistance(Operation $operation): ?array
    {
        if (! in_array($operation->kind, ['instance.deploy', 'instance.rollback'], true)
            || $operation->status->terminal() || $operation->started_at !== null || $operation->created_at === null
            || $operation->created_at->greaterThan(now()->subMinutes(self::UnclaimedAfterMinutes))) {
            return null;
        }

        return ['error_code' => 'deployment.executor_unclaimed'];
    }
}
