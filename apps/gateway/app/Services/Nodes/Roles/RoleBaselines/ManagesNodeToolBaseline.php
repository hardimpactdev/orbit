<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\NodeContainerScope;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Tools\ToolCatalog;

trait ManagesNodeToolBaseline
{
    /**
     * @param  list<string>  $tools
     */
    protected function convergeTools(Node $node, array $tools): void
    {
        foreach ($tools as $tool) {
            $this->convergeTool($node, $tool);
        }
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    protected function convergeTool(
        Node $node,
        string $tool,
        string $expectedState = 'installed',
        ?array $config = null,
    ): void {
        if (! $this->toolCatalog()->supports($tool)) {
            return;
        }

        if (! $this->toolCatalog()->supportsPlatform($tool, $node->platform)) {
            return;
        }

        NodeTool::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => $tool,
            ],
            [
                'expected_state' => $expectedState,
                'expected_version' => null,
                'config' => $config ?? $this->defaultToolConfig($tool, $node),
            ],
        );
    }

    protected function convergeOrbitCaddy(Node $node): void
    {
        $this->convergeTool($node, 'caddy');
    }

    /**
     * @param  list<string>  $tools
     */
    protected function removeTools(Node $node, array $tools): void
    {
        $supportedTools = array_values(array_filter(
            $tools,
            fn (string $tool): bool => $this->toolCatalog()->supports($tool),
        ));

        if ($supportedTools === []) {
            return;
        }

        NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', $supportedTools)
            ->delete();
    }

    abstract protected function toolCatalog(): ToolCatalog;

    /**
     * @return array<string, mixed>|null
     */
    private function defaultToolConfig(string $tool, Node $node): ?array
    {
        if ($tool !== 'caddy') {
            return null;
        }

        return [
            'container' => $this->defaultOrbitCaddyContainer($node)->spec(),
        ];
    }

    private function defaultOrbitCaddyContainer(Node $node): OrbitCaddyContainer
    {
        $wireGuardAddress = is_string($node->wireguard_address)
            ? trim($node->wireguard_address)
            : '';
        $names = OrbitContainerNames::forNodeScope(NodeContainerScope::forNode($node));

        if ($this->nodeHasIngressRole($node)) {
            $container = OrbitCaddyContainer::forPublicIngress(
                $wireGuardAddress !== '' ? $wireGuardAddress : null,
                $names,
            );

            return $this->platformAwareOrbitCaddyContainer($container, $node);
        }

        if ($wireGuardAddress === '') {
            $container = OrbitCaddyContainer::default($names);

            return $this->platformAwareOrbitCaddyContainer($container, $node);
        }

        $container = OrbitCaddyContainer::forPrivateNode(
            wireGuardAddress: $wireGuardAddress,
            names: $names,
            callerFacingAddress: $this->appDevelopmentCallerFacingAddress($node),
        );

        return $this->platformAwareOrbitCaddyContainer($container, $node);
    }

    private function platformAwareOrbitCaddyContainer(OrbitCaddyContainer $container, Node $node): OrbitCaddyContainer
    {
        if (! NodeHostPaths::isMacosPlatform($node->platform)) {
            return $container;
        }

        $dataRoot = NodeHostPaths::homeDirectoryFor($node->platform, $node->user).'/.local/share/orbit/caddy';
        $mounts = array_values(array_filter(
            array_map(
                fn (array $mount): ?array => $this->macosCaddyMount($mount, $dataRoot),
                $container->mounts(),
            ),
            fn (?array $mount): bool => $mount !== null,
        ));

        return OrbitCaddyContainer::fromConfig([
            ...$container->spec(),
            'mounts' => $mounts,
        ]);
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $mount
     * @return array{source: string, target: string, read_only: bool}|null
     */
    private function macosCaddyMount(array $mount, string $dataRoot): ?array
    {
        return match ($mount['source']) {
            '/var/lib/orbit/caddy/data' => [
                ...$mount,
                'source' => "{$dataRoot}/data",
            ],
            '/var/lib/orbit/caddy/config' => [
                ...$mount,
                'source' => "{$dataRoot}/config",
            ],
            '/home' => [
                ...$mount,
                'source' => '/Users',
                'target' => '/Users',
            ],
            '/run/php' => null,
            default => $mount,
        };
    }

    private function nodeHasIngressRole(Node $node): bool
    {
        return NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::Ingress->value)
            ->whereIn('status', [
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Active->value,
            ])
            ->exists();
    }

    private function appDevelopmentCallerFacingAddress(Node $node): ?string
    {
        if (! $this->nodeHasAppDevelopmentRole($node)) {
            return null;
        }

        return is_string($node->public_ipv4)
            ? trim($node->public_ipv4)
            : null;
    }

    private function nodeHasAppDevelopmentRole(Node $node): bool
    {
        return NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::AppDevelopment->value)
            ->whereIn('status', [
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Active->value,
            ])
            ->exists();
    }
}
