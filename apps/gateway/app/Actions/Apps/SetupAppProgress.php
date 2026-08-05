<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerRenderer;

final readonly class SetupAppProgress
{
    public function __construct(
        private SetupApp $setupApp,
        private AppRuntimeContainerRenderer $runtimeRenderer,
    ) {}

    public function for(App $app, Instance $instance, Node $node): SetupAppProgressPlan
    {
        return new SetupAppProgressPlan(
            setupApp: $this->setupApp,
            app: $this->runtimeRenderer->runtimeAppForInstance($app, $instance),
            instance: $instance,
            node: $node,
        );
    }
}
