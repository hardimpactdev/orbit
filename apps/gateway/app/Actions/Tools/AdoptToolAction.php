<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Data\Tools\AdoptToolData;
use App\Models\Operation;

final readonly class AdoptToolAction
{
    public function __construct(private StartToolOperationAction $operations) {}

    public function execute(AdoptToolData $data): Operation
    {
        return $this->operations->execute('adopt', $data->nodeId, $data->manager, $data->package, $data->versionConstraint);
    }
}
