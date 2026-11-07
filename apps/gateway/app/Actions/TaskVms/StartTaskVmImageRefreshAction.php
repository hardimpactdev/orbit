<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Models\Node;
use App\Models\Operation;

/**
 * Admits the refresh of one task VM host's image as one run. A refresh that is open on the host is returned
 * instead of a second one, so a release, the daily schedule and an operator can all ask; a release's commit waits behind it.
 */
final readonly class StartTaskVmImageRefreshAction
{
    public function __construct(private TaskVmImageRefreshIntent $intent) {}

    public function execute(Node $host, string $caller = 'task-vm', ?string $commit = null, bool $fromStock = false): Operation
    {
        return $this->intent->start($host, $caller, commit: $commit, fromStock: $fromStock);
    }
}
