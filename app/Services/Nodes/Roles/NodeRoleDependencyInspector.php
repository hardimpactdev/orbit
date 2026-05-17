<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use Illuminate\Support\Facades\DB;

class NodeRoleDependencyInspector
{
    /**
     * @var array<string, string>
     */
    private const array AppRoleEnvironments = [
        NodeRoleName::AppDevelopment->value => 'development',
        NodeRoleName::AppProduction->value => 'production',
    ];

    /**
     * @var list<string>
     */
    private const array DatabaseTools = [
        'postgres',
        'mysql',
    ];

    /**
     * @return list<string>
     */
    public function dependentSummaries(Node $node, NodeRoleAssignment $assignment): array
    {
        if (array_key_exists($assignment->role, self::AppRoleEnvironments)) {
            return $this->appRoleDependentSummaries($node, $assignment->role);
        }

        if ($assignment->role === NodeRoleName::Database->value) {
            return $this->databaseDependentSummaries($node);
        }

        return [];
    }

    public function removeOrbitOwnedDependents(Node $node, NodeRoleAssignment $assignment): void
    {
        if (array_key_exists($assignment->role, self::AppRoleEnvironments)) {
            $this->removeAppRoleDependents($node, $assignment->role);

            return;
        }

        if ($assignment->role === NodeRoleName::Database->value) {
            $this->removeDatabaseDependents($node);
        }
    }

    /**
     * @return list<string>
     */
    private function appRoleDependentSummaries(Node $node, string $role): array
    {
        $environment = self::AppRoleEnvironments[$role];
        $count = App::query()
            ->where('node_id', $node->id)
            ->where('environment', $environment)
            ->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} {$environment} app ".($count === 1 ? 'record' : 'records')];
    }

    /**
     * @return list<string>
     */
    private function databaseDependentSummaries(Node $node): array
    {
        $count = NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', self::DatabaseTools)
            ->count();

        if ($count === 0) {
            return [];
        }

        return ["{$count} database tool ".($count === 1 ? 'record' : 'records')];
    }

    private function removeAppRoleDependents(Node $node, string $role): void
    {
        $environment = self::AppRoleEnvironments[$role];

        DB::transaction(function () use ($node, $environment): void {
            $appIds = App::query()
                ->where('node_id', $node->id)
                ->where('environment', $environment)
                ->pluck('id')
                ->all();

            if ($appIds === []) {
                return;
            }

            ProxyRoute::query()
                ->whereIn('app_id', $appIds)
                ->delete();

            App::query()
                ->whereIn('id', $appIds)
                ->delete();
        });
    }

    private function removeDatabaseDependents(Node $node): void
    {
        NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', self::DatabaseTools)
            ->delete();
    }
}
