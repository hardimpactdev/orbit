<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Models\Node;
use App\Models\Operation;

/**
 * Admits the refresh of one task VM host's image and seed as one run. A refresh that is open on the host is returned
 * instead of a second one, so the release projection and the daily schedule can both ask.
 */
final readonly class StartTaskVmImageRefreshAction
{
    public function __construct(private TaskVmImageRefreshIntent $intent) {}

    public function execute(Node $host, string $caller = 'task-vm', string $seed = TaskVmImageRefreshIntent::SeedBuild): Operation
    {
        return $this->intent->start($host, $caller, null, $seed);
    }
}
