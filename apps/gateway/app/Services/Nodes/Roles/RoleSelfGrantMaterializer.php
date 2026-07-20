<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Access\NodePermissionRegistry;
use App\Services\Nodes\Access\ProjectInstancePermissionMigrator;
use Illuminate\Database\Eloquent\Builder;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class RoleSelfGrantMaterializer
{
    public function __construct(
        private NodePermissionPresets $presets,
        private NodePermissionNormalizer $normalizer,
        private NodePermissionRegistry $registry,
        private ProjectInstancePermissionMigrator $permissionMigrator,
    ) {}

    public function materializeOnRoleApplied(Node $node, NodeRoleName $role): void
    {
        if ($role === NodeRoleName::AppProduction) {
            $this->sanitizeWorkspacePermissionsForNode($node);
        }

        $this->persistEffectiveSelfGrant($node);
    }

    public function reconcileOnRoleRemoved(Node $node, NodeRoleName $role): void
    {
        $this->persistEffectiveSelfGrant($node);
    }

    /**
     * @return list<string>
     */
    public function effectiveSelfPermissions(Node $node): array
    {
        $permissions = $this->normalize([
            ...$this->roleDerivedSelfPermissions($node),
            ...$this->customSelfPermissions($node),
        ]);

        if (! in_array(NodeRoleName::AppProduction->value, $this->activeRoleNames($node), true)) {
            return $permissions;
        }

        return array_values(array_filter(
            $permissions,
            static fn (string $permission): bool => $permission !== '*' && ! str_starts_with($permission, 'workspace:'),
        ));
    }

    /**
     * @param  list<string>  $permissions
     */
    public function replaceCustomSelfPermissions(Node $node, array $permissions): void
    {
        $customPermissions = $this->normalize($permissions);

        if ($customPermissions === []) {
            $this->selfGrantQuery($node)->delete();

            return;
        }

        NodeAccess::query()->updateOrCreate([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
        ], [
            'permissions' => $customPermissions,
            'custom_permissions' => $customPermissions,
        ]);
    }

    /**
     * @return list<string>
     */
    public function roleDerivedSelfPermissions(Node $node): array
    {
        $permissions = [];

        foreach ($this->activeRoleNames($node) as $role) {
            $presetName = $this->presets->selfPresetNameForRole($role);

            if ($presetName === null) {
                continue;
            }

            $permissions = [
                ...$permissions,
                ...$this->presets->permissions($presetName),
            ];
        }

        return $this->normalize($permissions);
    }

    private function persistEffectiveSelfGrant(Node $node): void
    {
        $customPermissions = $this->customSelfPermissions($node);
        $effectivePermissions = $this->effectiveSelfPermissions($node);
        $existingGrant = $this->selfGrantQuery($node)->first();

        if ($effectivePermissions === [] && $customPermissions === []) {
            $this->selfGrantQuery($node)->delete();

            return;
        }

        NodeAccess::query()->updateOrCreate([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
        ], [
            'permissions' => $this->permissionMigrator->forStorage(
                $existingGrant?->permissions ?? [],
                $effectivePermissions,
                $this->registry,
            ),
            'custom_permissions' => $this->permissionMigrator->forStorage(
                $existingGrant?->custom_permissions ?? [],
                $customPermissions,
                $this->registry,
            ),
        ]);
    }

    private function sanitizeWorkspacePermissionsForNode(Node $node): void
    {
        $grants = NodeAccess::query()
            ->where(function (Builder $query) use ($node): void {
                $query
                    ->where('consumer_node_id', $node->id)
                    ->orWhere('serving_node_id', $node->id);
            })
            ->get();

        foreach ($grants as $grant) {
            $permissions = $this->withoutWorkspacePermissions(
                $this->permissionMigrator->current($grant->permissions ?? ['*']),
            );
            $customPermissions = $this->withoutWorkspacePermissions(
                $this->permissionMigrator->current($grant->custom_permissions ?? []),
            );

            $grant->forceFill([
                'permissions' => $this->permissionMigrator->forStorage(
                    $grant->permissions ?? ['*'],
                    $permissions,
                    $this->registry,
                ),
                'custom_permissions' => $this->permissionMigrator->forStorage(
                    $grant->custom_permissions ?? [],
                    $customPermissions,
                    $this->registry,
                ),
            ])->save();
        }
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
                array_push($expanded, ...$this->registry->all());

                continue;
            }

            $expanded[] = $permission;
        }

        $filtered = array_values(array_filter(
            $expanded,
            static fn (string $permission): bool => $permission !== '*' && ! str_starts_with($permission, 'workspace:'),
        ));

        return $this->normalize($filtered);
    }

    /**
     * @return list<string>
     */
    private function customSelfPermissions(Node $node): array
    {
        $grant = $this->selfGrantQuery($node)->first();

        if (! $grant instanceof NodeAccess) {
            return [];
        }

        return $this->normalize(
            $this->permissionMigrator->current($grant->custom_permissions ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function activeRoleNames(Node $node): array
    {
        return $node
            ->roleAssignments()
            ->where('status', NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->pluck('role')
            ->map(fn (mixed $role): string => (string) $role)
            ->all();
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function normalize(array $permissions): array
    {
        return $this->normalizer->normalize($permissions)->permissions;
    }

    /**
     * @return Builder<NodeAccess>
     */
    private function selfGrantQuery(Node $node): Builder
    {
        return NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->where('serving_node_id', $node->id);
    }
}
