<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;

final readonly class ToolAppNodeResolver
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function resolve(?string $app): ?Node
    {
        if ($app === null) {
            return null;
        }

        $model = App::query()
            ->with('node')
            ->where(static function (Builder $query) use ($app): void {
                $query->where('name', $app)
                    ->orWhere('domain', $app);
            })
            ->first();

        if (! $model instanceof App && str_contains($app, '.')) {
            [$appName, $nodeTld] = explode('.', $app, limit: 2);

            if ($appName !== '' && $nodeTld !== '') {
                $model = App::query()
                    ->with('node')
                    ->where('name', $appName)
                    ->whereHas('node', function (Builder $query) use ($nodeTld): void {
                        $query
                            ->whereIn('id', $this->nodeRoleAssignments->activeAppHostNodeIds())
                            ->where('status', NodeStatus::Active->value)
                            ->where('tld', $nodeTld);
                    })
                    ->first();
            }
        }

        if (! $model instanceof App || ! $model->node instanceof Node) {
            return null;
        }

        if (! $model->node->isActive() || ! $this->nodeRoleAssignments->nodeHasActiveAppHostRole($model->node)) {
            return null;
        }

        return $model->node;
    }
}
