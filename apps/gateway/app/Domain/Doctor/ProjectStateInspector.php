<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Infrastructure\Doctor\NativeProjectStateInspector;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Container\Attributes\Bind;

#[Bind(NativeProjectStateInspector::class)]
interface ProjectStateInspector
{
    public function inspect(Project $project, Node $node): ProjectInspectionData;
}
