<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Carbon\CarbonImmutable;

/** Measured kind budgets replace this provisional default in one place. */
final readonly class OperationRunBudgets
{
    private const int DefaultSeconds = 300;

    /** A number is a fixed budget; a field name selects a server-captured admission budget. */
    private const array SecondsByKind = ['instance.create' => 'run_budget_seconds'];

    public static function seconds(Operation $operation): int
    {
        $budget = self::SecondsByKind[$operation->kind] ?? self::DefaultSeconds;
        if (is_int($budget)) {
            return $budget;
        }
        $seconds = $operation->input[$budget] ?? null;

        return is_int($seconds) && $seconds > 0 ? $seconds : self::DefaultSeconds;
    }

    public static function deadline(Operation $operation): ?CarbonImmutable
    {
        $start = $operation->started_at ?? $operation->created_at;

        return $start === null ? null : CarbonImmutable::instance($start)->addSeconds(self::seconds($operation));
    }
}
