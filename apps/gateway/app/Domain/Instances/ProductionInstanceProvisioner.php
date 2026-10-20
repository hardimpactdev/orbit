<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\CreateInstanceData;
use App\Infrastructure\Instances\NativeProductionInstanceProvisioner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeProductionInstanceProvisioner::class)]
interface ProductionInstanceProvisioner
{
    /** @return array{instance: Instance, created: bool} */
    public function execute(CreateInstanceData $data, Project $project, Node $node, ?string $root): array;
}
