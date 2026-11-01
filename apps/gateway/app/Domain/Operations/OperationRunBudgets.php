<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Carbon\CarbonImmutable;

/** Measured kind budgets replace this provisional default in one place. */
final readonly class OperationRunBudgets
{
    private const int DefaultSeconds = 300;

    /**
     * A number is a fixed budget; a field name selects a server-captured admission budget. Fixed budgets are the
     * 14-day p95 of successful runs, rounded up to the next five minutes. A kind without a successful run carries a
     * marked provisional value until one is measured.
     */
    private const array SecondsByKind = [
        'instance.create' => 'run_budget_seconds',
        'certificate.converge' => 1200,
        'cluster.router_set' => 2700, // provisional: no successful run yet
        'cluster.router_unset' => 2700, // provisional: no successful run yet
        'node.converge' => 3900,
        'node.network_converge' => 1500,
        'node.prepare_php_packages' => 3600,
        'node.remove' => 2100,
        'node.role_add' => 2700,
        'node.role_converge' => 3900, // provisional: node.converge's budget
        'node.role_relocate' => 2700, // provisional: node.role_add's budget
        'task_vm.destroy' => 2700,
        'task_vm.image_build' => 2400,
        'task_vm.sweep_host' => 600,
        'task_vm.upcloud_image_build' => 2100,
        'tasks.provision' => 3900,
    ];

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
