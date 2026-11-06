<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskReviewRoundIntent;
use App\Models\Operation;
use App\Models\Task;

/** Admits one review round of the automatic mode as one Swamp run on the exact head of the pull request. */
final readonly class StartTaskReviewRoundAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskReviewRoundIntent $intent) {}

    public function execute(Task $group, string $head, string $caller, ?string $request): Operation
    {
        $this->extension->execute();

        return $this->intent->start($group, $head, $caller, $request);
    }
}
