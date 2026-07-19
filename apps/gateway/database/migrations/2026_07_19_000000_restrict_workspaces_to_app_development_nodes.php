<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * This one-shot migration normalizes historical workspace state and app-prod grants.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('workspaces')
                ->where('lifecycle_status', 'setting_up')
                ->update([
                    'lifecycle_status' => 'setup-pending',
                    'updated_at' => now(),
                ]);

            $productionNodeIds = [];
            $productionNodeIdValues = DB::table('node_role')
                ->where('role', 'app-prod')
                ->where('status', 'active')
                ->pluck('node_id');

            foreach ($productionNodeIdValues as $nodeId) {
                $productionNodeIds[] = (int) $nodeId;
            }

            if ($productionNodeIds === []) {
                return;
            }

            $this->assertNoProductionWorkspaceOwnership($productionNodeIds);

            $grantIds = DB::table('node_access')
                ->where(function (Builder $query) use ($productionNodeIds): void {
                    $query
                        ->whereIn('consumer_node_id', $productionNodeIds)
                        ->orWhereIn('serving_node_id', $productionNodeIds);
                })
                ->pluck('id')
                ->map(static fn (mixed $grantId): int => (int) $grantId)
                ->all();

            foreach ($grantIds as $grantId) {
                $grant = DB::table('node_access')->where('id', $grantId);
                $permissions = $this->withoutWorkspacePermissions($this->decodePermissions($grant->value(
                    'permissions',
                )));
                $customPermissions = $this->withoutWorkspacePermissions(
                    $this->decodePermissions($grant->value('custom_permissions')),
                );

                DB::table('node_access')
                    ->where('id', $grantId)
                    ->update([
                        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                        'custom_permissions' => json_encode($customPermissions, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $value): array
    {
        $decoded = is_string($value)
            ? json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR)
            : $value;

        if (! is_array($decoded)) {
            return [];
        }

        $permissions = [];

        foreach ($decoded as $permission) {
            if (is_string($permission) && $permission !== '') {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function withoutWorkspacePermissions(array $permissions): array
    {
        $expanded = [];

        foreach ($permissions as $permission) {
            if ($permission === '*') {
                array_push($expanded, ...app(NodePermissionRegistry::class)->all());

                continue;
            }

            $expanded[] = $permission;
        }

        return array_values(array_unique(array_filter(
            $expanded,
            static fn (string $permission): bool => $permission !== '*' && ! str_starts_with($permission, 'workspace:'),
        )));
    }

    /**
     * @param  list<int>  $productionNodeIds
     */
    private function assertNoProductionWorkspaceOwnership(array $productionNodeIds): void
    {
        $nodeNamesById = [];
        $nodeIdsByName = [];

        foreach (DB::table('nodes')->select(['id', 'name'])->get() as $node) {
            if (! is_object($node) || ! is_numeric($node->id ?? null) || ! is_string($node->name ?? null)) {
                continue;
            }

            $nodeId = (int) $node->id;
            $nodeNamesById[$nodeId] = $node->name;
            $nodeIdsByName[$node->name] = $nodeId;
        }

        $workspaceOwnership = DB::table('workspaces')
            ->join('app_instances', 'app_instances.id', '=', 'workspaces.app_instance_id')
            ->select(['workspaces.name as workspace_name', 'app_instances.driver_config'])
            ->orderBy('workspaces.id')
            ->get();

        foreach ($workspaceOwnership as $ownership) {
            if (! is_object($ownership) || ! is_string($ownership->driver_config ?? null)) {
                continue;
            }

            $config = json_decode($ownership->driver_config, associative: true, flags: JSON_THROW_ON_ERROR);
            $data = is_array($config['data'] ?? null) ? $config['data'] : [];
            $nodeId = (int) ($data['node_id'] ?? 0);

            if (! array_key_exists($nodeId, $nodeNamesById)) {
                $nodeName = is_string($data['node'] ?? null) ? $data['node'] : null;
                $nodeId = $nodeName !== null ? $nodeIdsByName[$nodeName] ?? 0 : 0;
            }

            if (! in_array($nodeId, $productionNodeIds, strict: true)) {
                continue;
            }

            $nodeName = $nodeNamesById[$nodeId] ?? 'unknown';
            $workspaceName = is_string($ownership->workspace_name ?? null)
                ? $ownership->workspace_name
                : 'unknown';

            throw new RuntimeException(
                "Workspace [{$workspaceName}] is still owned by app production node [{$nodeName}].",
            );
        }
    }

    public function down(): void {}
};
