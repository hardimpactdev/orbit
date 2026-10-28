<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Data\Tools\InstallToolData;
use App\Models\Operation;

final readonly class InstallToolAction
{
    public function __construct(private StartToolOperationAction $operations) {}

    public function execute(InstallToolData $data): Operation
    {
        return $this->operations->execute('install', $data->nodeId, $data->manager, $data->package, $data->versionConstraint);
    }
}
