<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;

final readonly class DoctorAppInstanceTarget
{
    public function __construct(
        public Project $app,
        public AppInstance $instance,
        public Node $node,
    ) {}

    public function scope(?string $workspace = null): DoctorTargetScope
    {
        return DoctorTargetScope::from(
            app: $this->app->name,
            workspace: $workspace,
            appInstanceId: $this->instance->id,
            appInstance: $this->instance->name,
        );
    }
}
