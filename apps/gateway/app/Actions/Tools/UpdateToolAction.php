<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\Operation;
use App\Models\Tool;

final readonly class UpdateToolAction
{
    public function __construct(private StartToolOperationAction $operations) {}

    /** @param array<string, mixed>|null $repository */
    public function execute(Tool $tool, ?array $repository = null): Operation
    {
        $tool->loadMissing('manager');

        return $this->operations->execute('update', $tool->node_id, $tool->manager->name, $tool->package, $tool->version_constraint, $tool, $repository);
    }
}
