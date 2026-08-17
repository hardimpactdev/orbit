<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\App;
use App\Models\Instance;
use App\Models\Workspace;

final readonly class WorkspaceProxyRouteOwnership
{
    public function __construct(
        public Workspace $workspace,
        public Instance $instance,
        public App $app,
    ) {}
}
