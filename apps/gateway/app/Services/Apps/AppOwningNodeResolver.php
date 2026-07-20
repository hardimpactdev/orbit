<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\Node;
use App\Models\Project;
use RuntimeException;

final readonly class AppOwningNodeResolver
{
    public function resolve(Project $app): Node
    {
        $app->loadMissing('node');

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        return $node;
    }
}
