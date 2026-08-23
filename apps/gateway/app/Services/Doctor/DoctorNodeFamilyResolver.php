<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Firewall\FirewallTargetPlatform;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Support\Collection;

final readonly class DoctorNodeFamilyResolver
{
    private const array SUPPORTED_FAMILIES = [
        'node',
        'app',
        'workspace',
        'process',
        'proxy',
        'firewall_rule',
        'tool',
        'schedule',
        'database_connection',
    ];

    private const array CONTROL_CATEGORIES = ['node'];

    private const array GATEWAY_CATEGORIES = ['node', 'schedule'];

    private const array APP_CATEGORIES = [
        'node',
        'app',
        'workspace',
        'process',
        'proxy',
        'tool',
        'database_connection',
    ];

    private const array APP_PRODUCTION_CATEGORIES = [
        'node',
        'app',
        'process',
        'proxy',
        'tool',
        'database_connection',
    ];

    private const array DATABASE_CATEGORIES = ['node', 'tool', 'process'];

    private const array AGENT_CATEGORIES = ['node', 'tool', 'proxy'];

    private const array INGRESS_CATEGORIES = ['node', 'proxy', 'tool'];

    private const array ROUTER_CATEGORIES = ['node', 'proxy'];

    private const array WEBSOCKET_CATEGORIES = ['node', 'tool'];

    private const array S3_CATEGORIES = ['node', 'tool', 'proxy'];

    private const array METRICS_CATEGORIES = ['node', 'tool', 'process', 'proxy'];

    private const array ROLE_CATEGORY_PRIORITY = [
        NodeRoleName::Gateway->value,
        NodeRoleName::AppDevelopment->value,
        NodeRoleName::AppProduction->value,
        NodeRoleName::Database->value,
        NodeRoleName::Agent->value,
        NodeRoleName::Ingress->value,
        NodeRoleName::Router->value,
        NodeRoleName::WebSocket->value,
        NodeRoleName::S3->value,
        NodeRoleName::Metrics->value,
    ];

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private DoctorNodeScheduleTargets $scheduleTargets,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return self::SUPPORTED_FAMILIES;
    }

    /**
     * @return list<string>
     */
    public function categoriesForRole(string $role): array
    {
        return match ($role) {
            'operator' => self::CONTROL_CATEGORIES,
            NodeRoleName::Gateway->value => self::GATEWAY_CATEGORIES,
            NodeRoleName::AppDevelopment->value => self::APP_CATEGORIES,
            NodeRoleName::AppProduction->value => self::APP_PRODUCTION_CATEGORIES,
            NodeRoleName::Database->value => self::DATABASE_CATEGORIES,
            NodeRoleName::Agent->value => self::AGENT_CATEGORIES,
            NodeRoleName::Ingress->value => self::INGRESS_CATEGORIES,
            NodeRoleName::Router->value => self::ROUTER_CATEGORIES,
            NodeRoleName::WebSocket->value => self::WEBSOCKET_CATEGORIES,
            NodeRoleName::S3->value => self::S3_CATEGORIES,
            NodeRoleName::Metrics->value => self::METRICS_CATEGORIES,
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function categoriesForNode(Node $node): array
    {
        /** @var list<string> $rolePriority */
        $rolePriority = self::ROLE_CATEGORY_PRIORITY;
        $activeRoles = array_filter(
            $rolePriority,
            fn (string $role): bool => $this->nodeRoleAssignments->nodeHasActiveRole($node, $role),
        );
        $categoriesByRole = array_map($this->categoriesForRole(...), $activeRoles);
        $roleCategories = array_merge(...$categoriesByRole);
        $categories = $roleCategories === [] ? self::CONTROL_CATEGORIES : $roleCategories;
        $hasActiveRole = $activeRoles !== [] || $this->nodeHasAnyActiveRole($node);
        $overlays = array_keys(array_filter(
            [
                'process' => $hasActiveRole,
                'tool' =>
                    in_array('tool', $categories, strict: true)
                        || $this->nodeRoleAssignments->nodeIsGateway($node)
                        && $this->nodeRoleAssignments->nodeHasActiveVpnRole($node)
                        || NodeTool::query()->where('node_id', $node->id)->exists(),
                'firewall_rule' =>
                    $node->isActive()
                        && FirewallTargetPlatform::isUbuntu($node->platform)
                        && $this->nodeRoleAssignments->nodeCanOwnFirewallRules($node),
                'schedule' =>
                    in_array('schedule', $categories, strict: true)
                        || $this->scheduleTargets->expectedFor($node)->exists(),
            ],
            static fn (bool $enabled): bool => $enabled,
        ));

        return array_values(array_unique([...$categories, ...$overlays]));
    }

    /**
     * @param  list<string>  $families
     */
    public function nodeSupportsFamilies(Node $node, array $families): bool
    {
        if ($families === []) {
            return true;
        }

        $categories = $this->categoriesForNode($node);

        return array_all(
            $families,
            static fn (string $family): bool => in_array($family, $categories, strict: true),
        );
    }

    /**
     * @param  list<string>  $families
     * @return list<string>
     */
    public function selectedFamiliesForNode(Node $node, array $families): array
    {
        $nodeFamilies = $this->categoriesForNode($node);

        return $families === []
            ? $nodeFamilies
            : array_values(array_intersect($families, $nodeFamilies));
    }

    /**
     * @param  Collection<int, Node>  $targets
     * @param  list<string>  $families
     * @return list<string>
     */
    public function fleetFamilies(Collection $targets, array $families): array
    {
        if ($families !== []) {
            return $families;
        }

        /** @var list<string> $resolved */
        $resolved = $targets
            ->flatMap($this->categoriesForNode(...))
            ->unique()
            ->values()
            ->all();

        return $resolved;
    }

    private function nodeHasAnyActiveRole(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeHasAnyActiveRole(
            $node,
            array_map(
                static fn (NodeRoleName $role): string => $role->value,
                NodeRoleName::cases(),
            ),
        );
    }
}
