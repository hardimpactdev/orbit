<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;

final readonly class DoctorAppInstanceTarget
{
    public function __construct(
        public App $app,
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
