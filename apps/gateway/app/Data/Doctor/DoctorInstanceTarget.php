<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;

final readonly class DoctorInstanceTarget
{
    public function __construct(
        public App $app,
        public Instance $instance,
        public Node $node,
    ) {}

    public function scope(?string $workspace = null): DoctorTargetScope
    {
        return DoctorTargetScope::from(
            app: $this->app->name,
            workspace: $workspace,
            instanceId: $this->instance->id,
            instance: $this->instance->name,
        );
    }
}
