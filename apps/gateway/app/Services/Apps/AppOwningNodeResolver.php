<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class AppOwningNodeResolver
{
    public function __construct(
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public function resolve(App $app, ?Instance $instance = null): Node
    {
        $node = $this->placement->runtimeNode($app, $instance);

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        return $node;
    }
}
