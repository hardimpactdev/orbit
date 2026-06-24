<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Models\App;
use App\Models\Node;

final readonly class SetupAppProgress
{
    public function __construct(
        private SetupApp $setupApp,
    ) {}

    public function for(App $app, Node $node): SetupAppProgressPlan
    {
        return new SetupAppProgressPlan(
            setupApp: $this->setupApp,
            app: $app,
            node: $node,
        );
    }
}
