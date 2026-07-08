<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeContainerScope;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;

class NodeToolBaselineConfigRenderer
{
    /**
     * @return array<string, mixed>|null
     */
    public function render(string $tool, Node $node): ?array
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

        $home = NodeHostPaths::homeDirectoryFor($node->platform, $node->user);
        $configRoot = "{$home}/.config/orbit";
        $dataRoot = "{$home}/.local/share/orbit/caddy";
        $mounts = array_values(array_filter(
            array_map(
                fn (array $mount): ?array => $this->macosCaddyMount($mount, $configRoot, $dataRoot),
                $container->mounts(),
            ),
            static fn (?array $mount): bool => $mount !== null,
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
    private function macosCaddyMount(array $mount, string $configRoot, string $dataRoot): ?array
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
            '/etc/caddy/Caddyfile' => [
                ...$mount,
                'source' => "{$configRoot}/caddy/Caddyfile",
            ],
            '/etc/caddy/orbit' => [
                ...$mount,
                'source' => "{$configRoot}/caddy/orbit",
            ],
            '/etc/caddy/sites' => [
                ...$mount,
                'source' => "{$configRoot}/caddy/sites",
            ],
            '/etc/orbit' => [
                ...$mount,
                'source' => $configRoot,
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

        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return '0.0.0.0';
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
