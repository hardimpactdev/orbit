<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeTool;
use Orbit\Core\Php\PhpCliVariant;

/**
 * Resolves the effective php-cli variant for doctor, install, and restore.
 *
 * Role ownership is authoritative for app-dev/app-prod nodes so legacy rows with
 * null or incomplete config never default production nodes toward coverage/PCOV.
 */
final class PhpCliVariantResolver
{
    public function forTool(NodeTool $tool): PhpCliVariant
    {
        $tool->loadMissing('node');

        return $this->resolve(
            node: $tool->node instanceof Node ? $tool->node : null,
            config: is_array($tool->config) ? $tool->config : [],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function resolve(?Node $node, array $config = []): PhpCliVariant
    {
        $role = $node instanceof Node ? $this->appRoleForNode($node) : null;

        if ($role !== null) {
            return PhpCliVariant::forRole($role);
        }

        $stored = PhpCliVariant::tryFromMixed($config['variant'] ?? null);

        if ($stored instanceof PhpCliVariant) {
            return $stored;
        }

        // Manual installs without an app role remain developer-oriented by default.
        return PhpCliVariant::Coverage;
    }

    /**
     * Active/pending app role that owns php-cli baseline intent, if any.
     * app-dev wins when both are present (roles conflict in practice).
     */
    public function appRoleForNode(Node $node): ?string
    {
        $roles = $node
            ->roleAssignments()
            ->whereIn('status', [
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Active->value,
            ])
            ->pluck('role')
            ->all();

        if (in_array(NodeRoleName::AppDevelopment->value, $roles, true)) {
            return NodeRoleName::AppDevelopment->value;
        }

        if (in_array(NodeRoleName::AppProduction->value, $roles, true)) {
            return NodeRoleName::AppProduction->value;
        }

        return null;
    }

    /**
     * Whether NodeTool.config lacks a usable variant (legacy null/empty/stale shape).
     *
     * @param  array<string, mixed>|null  $config
     */
    public function configLacksVariant(?array $config): bool
    {
        if ($config === null || $config === []) {
            return true;
        }

        return PhpCliVariant::tryFromMixed($config['variant'] ?? null) === null;
    }
}
