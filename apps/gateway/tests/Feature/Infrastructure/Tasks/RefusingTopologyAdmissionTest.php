<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Infrastructure\Tasks\RefusingTopologyAdmission;
use App\Models\Task;

it('admits a subtask without declared workload nodes and refuses one that declares them', function (): void {
    $admission = app(TaskTopologyAdmission::class);
    $group = new Task;

    expect($admission)->toBeInstanceOf(RefusingTopologyAdmission::class);
    $admission->prepare($group, new Task(['topology' => []]));
    $admission->prepare($group, new Task);

    expect(fn () => $admission->prepare($group, new Task(['topology' => ['app-dev']])))
        ->toThrow(TaskCapacityException::class, 'Declared topology is not available.');
});
