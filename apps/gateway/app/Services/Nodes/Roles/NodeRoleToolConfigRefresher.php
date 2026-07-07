<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolCatalog;

class NodeRoleToolConfigRefresher
{
    private const array METADATA_DERIVED_FIELDS = [
        'public_ipv4',
        'user',
    ];

    private const array ORBIT_CADDY_ROLES = [
        NodeRoleName::Agent->value,
        NodeRoleName::AppDevelopment->value,
        NodeRoleName::AppProduction->value,
        NodeRoleName::Ingress->value,
        NodeRoleName::Router->value,
    ];

    public function __construct(
        private readonly NodeToolBaselineConfigRenderer $configRenderer,
        private readonly ToolCatalog $toolCatalog,
    ) {}

    /**
     * @param  array<string, bool|string>  $providedFields
     */
    public function refreshForProvidedNodeFields(Node $node, array $providedFields): void
    {
        if (! $this->touchesMetadataDerivedFields($providedFields)) {
            return;
        }

        if (! $this->nodeHasOrbitCaddyRole($node)) {
            return;
        }

        if (! $this->toolCatalog->supports('caddy')) {
            return;
        }

        if (! $this->toolCatalog->supportsPlatform('caddy', $node->platform)) {
            return;
        }

        $config = $this->configRenderer->render('caddy', $node);

        if ($config === null) {
            return;
        }

        NodeTool::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => 'caddy',
            ],
            [
                'expected_state' => 'installed',
                'expected_version' => null,
                'config' => $config,
            ],
        );
    }

    /**
     * @param  array<string, bool|string>  $providedFields
     */
    private function touchesMetadataDerivedFields(array $providedFields): bool
    {
        return array_any(
            self::METADATA_DERIVED_FIELDS,
            static fn (string $field): bool => array_key_exists($field, $providedFields),
        );
    }

    private function nodeHasOrbitCaddyRole(Node $node): bool
    {
        return $node
            ->roleAssignments()
            ->whereIn('role', self::ORBIT_CADDY_ROLES)
            ->whereIn('status', [
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Active->value,
            ])
            ->exists();
    }
}
