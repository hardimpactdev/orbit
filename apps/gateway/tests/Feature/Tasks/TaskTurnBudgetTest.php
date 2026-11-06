<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Models\Operation;

it('gives a turn one hour, and its observe step ends before the Gateway does', function (): void {
    $budget = OperationRunBudgets::seconds((new Operation)->forceFill(['kind' => 'tasks.turn']));
    $workflow = (string) file_get_contents(base_path('../../swamp/workflows/workflow-operation-task-turn.yaml'));

    expect($budget)->toBe(3600)
        ->and($workflow)->toContain('--max-time 3300', 'timeout: 3300000');
});
