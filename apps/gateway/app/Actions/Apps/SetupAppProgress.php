<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppRuntimeContainerRenderer;

final readonly class SetupAppProgress
{
    public function __construct(
        private SetupApp $setupApp,
        private AppRuntimeContainerRenderer $runtimeRenderer,
    ) {}

    public function for(Project $app, AppInstance $instance, Node $node): SetupAppProgressPlan
    {
        return new SetupAppProgressPlan(
            setupApp: $this->setupApp,
            app: $this->runtimeRenderer->runtimeAppForInstance($app, $instance),
            instance: $instance,
            node: $node,
        );
    }
}
