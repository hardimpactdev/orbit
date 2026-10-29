<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Reads unclaimed Conn work without executing effects or changing its target hold. */
final readonly class ConnOperationHealth
{
    public const int UnclaimedAfterMinutes = 5;

    /** @return Builder<Operation> */
    public function unclaimed(): Builder
    {
        return Operation::query()->whereIn('kind', ['conn.pairing_create', 'conn.pairing_revoke'])
            ->whereNotIn('status', [OperationStatus::Succeeded->value, OperationStatus::Failed->value, OperationStatus::Cancelled->value])
            ->whereNull('started_at')->where('created_at', '<=', Carbon::now()->subMinutes(self::UnclaimedAfterMinutes));
    }

    /** @return array{error_code:string}|null */
    public function assistance(Operation $operation): ?array
    {
        return $this->unclaimed()->whereKey($operation->id)->exists() ? ['error_code' => 'conn.executor_unclaimed'] : null;
    }
}
