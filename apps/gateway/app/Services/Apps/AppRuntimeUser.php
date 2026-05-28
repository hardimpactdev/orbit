<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Str;

final readonly class AppRuntimeUser
{
    public function forApp(App $app): string
    {
        if (! $this->isProduction($app)) {
            $app->loadMissing('node');

            return $app->node?->user ?: 'orbit';
        }

        return $this->safeRuntimeUser($app->name);
    }

    private function isProduction(App $app): bool
    {
        $app->loadMissing('node');

        if ($app->environment === 'production') {
            return true;
        }

        return $app->node instanceof Node
            && app(NodeRoleAssignments::class)->nodeHasActiveRole($app->node, 'app-prod');
    }

    private function safeRuntimeUser(string $name): string
    {
        $slug = Str::of($name)->slug('-')->lower()->toString();
        $slug = $slug !== '' ? $slug : 'app';
        $suffix = substr(hash('sha1', $slug), 0, 6);
        $base = substr($slug, 0, 18);

        return "orbit-{$base}-{$suffix}";
    }
}
