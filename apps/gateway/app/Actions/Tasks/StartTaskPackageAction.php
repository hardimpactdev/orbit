<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPackageData;
use App\Domain\Tasks\TaskPackageIntent;
use App\Models\Operation;
use App\Models\Task;

final readonly class StartTaskPackageAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskPackageIntent $operations) {}

    public function execute(Task $group, StartTaskPackageData $input, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, $input, $triggeredBy, $requestId);
    }
}
