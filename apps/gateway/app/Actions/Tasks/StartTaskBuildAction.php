<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskBuildIntent;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Models\Operation;
use App\Models\Task;

/** Admits the build of an approved spec. The gate check calls it with the head it saw; there is no route. */
final readonly class StartTaskBuildAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private OperationIdempotency $idempotency, private TaskPullRequestEvidence $evidence, private TaskBuildIntent $intent) {}

    public function execute(Task $group, string $head, string $caller, ?string $requestId): Operation
    {
        $this->extension->execute();
        if (($existing = $this->idempotency->replay(OperationKinds::TasksBuild)) !== null) {
            return $existing;
        }
        // The one read of GitHub, before the group lock: the pull request is a draft on this head, and in manual mode the reviewer approved it.
        $this->evidence->build($group, $head);

        return $this->intent->start($group, $head, $caller, $requestId);
    }
}
