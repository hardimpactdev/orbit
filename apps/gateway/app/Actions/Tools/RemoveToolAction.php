<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\Operation;
use App\Models\Tool;

final readonly class RemoveToolAction
{
    public function __construct(private StartToolOperationAction $operations) {}

    public function execute(Tool $tool): Operation
    {
        $tool->loadMissing('manager');

        return $this->operations->execute('remove', $tool->node_id, $tool->manager->name, $tool->package, $tool->version_constraint, $tool);
    }
}
