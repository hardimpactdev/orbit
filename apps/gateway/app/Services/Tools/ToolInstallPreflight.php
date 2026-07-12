<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Tools\UserScopedCliUsers;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class ToolInstallPreflight
{
    private const string DOCKER_COMPATIBLE_PROVIDER = 'docker-compatible';

    private const string DOCKER_ISOLATION = 'docker';

    private const string DOCKER_NETWORK_ISOLATION = 'docker-network-namespace';

    private const string UNPRIVILEGED_USER_ISOLATION = 'unprivileged-user';

    private const string DOCKER_PROVIDER_PROBE_SCRIPT = <<<'BASH'
        set -eu
        if ! command -v docker >/dev/null 2>&1; then
            printf '%s\n' 'Docker-compatible container provider command is missing.' >&2
            exit 66
        fi
        if ! docker info >/dev/null 2>&1; then
            printf '%s\n' 'Docker-compatible container provider is unreachable.' >&2
            exit 67
        fi
        BASH;

    public function __construct(
        private ToolCatalog $catalog,
        private ToolScriptDispatcher $scripts,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function check(string $tool, Node $node): ?ToolRegistryFailure
    {
        $nodeMetadataFailure = $this->nodeMetadataFailure($tool, $node);

        if ($nodeMetadataFailure instanceof ToolRegistryFailure) {
            return $nodeMetadataFailure;
        }

        $operatingSystemFailure = $this->operatingSystemFailure($tool, $node);

        if ($operatingSystemFailure instanceof ToolRegistryFailure) {
            return $operatingSystemFailure;
        }

        $gatewayLocalFailure = $this->gatewayLocalFailure($tool, $node);

        if ($gatewayLocalFailure instanceof ToolRegistryFailure) {
            return $gatewayLocalFailure;
        }

        $declarationFailure = $this->declarationFailure($tool, $node);

        if ($declarationFailure instanceof ToolRegistryFailure) {
            return $declarationFailure;
        }

        $runtimeUserFailure = $this->runtimeUserFailure($tool, $node);

        if ($runtimeUserFailure instanceof ToolRegistryFailure) {
            return $runtimeUserFailure;
        }

        return $this->containerProviderFailure($tool, $node);
    }

    private function nodeMetadataFailure(string $tool, Node $node): ?ToolRegistryFailure
    {
        if (! $node->isActive()) {
            return ToolRegistryFailure::constraintUnsatisfied(
                tool: $tool,
                node: $node->name,
                constraint: 'node_status',
                context: [
                    'required' => 'active',
                    'actual' => $node->status->value,
                ],
            );
        }

        if (! $this->catalog->requiresRouteTld($tool) || trim((string) $node->tld) !== '') {
            return null;
        }

        return ToolRegistryFailure::constraintUnsatisfied(
            tool: $tool,
            node: $node->name,
            constraint: 'route_tld',
            context: [
                'required' => 'configured',
                'actual' => null,
            ],
        );
    }

    private function operatingSystemFailure(string $tool, Node $node): ?ToolRegistryFailure
    {
        $supportedOperatingSystems = $this->catalog->supportedOperatingSystems($tool);
        $operatingSystem = $this->catalog->operatingSystemForPlatform($node->platform);

        if ($operatingSystem !== null && in_array($operatingSystem, $supportedOperatingSystems, strict: true)) {
            return null;
        }

        return ToolRegistryFailure::constraintUnsatisfied(
            tool: $tool,
            node: $node->name,
            constraint: 'operating_system',
            context: [
                'required' => $supportedOperatingSystems,
                'actual' => $operatingSystem,
                'platform' => $node->platform,
            ],
        );
    }

    private function gatewayLocalFailure(string $tool, Node $node): ?ToolRegistryFailure
    {
        $requiredGatewayLocal = $this->catalog->gatewayLocal($tool);
        $actualGatewayLocal = $this->nodeRoleAssignments->nodeIsGateway($node);

        if ($requiredGatewayLocal === $actualGatewayLocal) {
            return null;
        }

        return ToolRegistryFailure::constraintUnsatisfied(
            tool: $tool,
            node: $node->name,
            constraint: 'gateway_local',
            context: [
                'required' => $requiredGatewayLocal,
                'actual' => $actualGatewayLocal,
            ],
        );
    }

    private function declarationFailure(string $tool, Node $node): ?ToolRegistryFailure
    {
        $runtimeUser = $this->catalog->runtimeUser($tool);
        $isolation = $this->catalog->isolation($tool);
        $containerProvider = $this->catalog->requiredContainerProvider($tool);
        $supportedIsolation = [
            self::DOCKER_ISOLATION,
            self::DOCKER_NETWORK_ISOLATION,
            self::UNPRIVILEGED_USER_ISOLATION,
        ];

        if ($runtimeUser !== null && preg_match(UserScopedCliUsers::USERNAME_PATTERN, $runtimeUser) !== 1) {
            return ToolRegistryFailure::constraintUnsatisfied(
                tool: $tool,
                node: $node->name,
                constraint: 'runtime_user',
                context: [
                    'required' => 'valid-system-username',
                    'actual' => $runtimeUser,
                ],
            );
        }

        if ($isolation !== null && ! in_array($isolation, $supportedIsolation, strict: true)) {
            return ToolRegistryFailure::constraintUnsatisfied(
                tool: $tool,
                node: $node->name,
                constraint: 'isolation',
                context: [
                    'required' => $supportedIsolation,
                    'actual' => $isolation,
                ],
            );
        }

        if ($isolation === self::UNPRIVILEGED_USER_ISOLATION && $runtimeUser === null) {
            return ToolRegistryFailure::constraintUnsatisfied(
                tool: $tool,
                node: $node->name,
                constraint: 'runtime_user',
                context: [
                    'required' => 'configured',
                    'actual' => null,
                ],
            );
        }

        if (
            in_array($isolation, [self::DOCKER_ISOLATION, self::DOCKER_NETWORK_ISOLATION], strict: true)
            && $containerProvider !== self::DOCKER_COMPATIBLE_PROVIDER
        ) {
            return ToolRegistryFailure::constraintUnsatisfied(
                tool: $tool,
                node: $node->name,
                constraint: 'container_provider',
                context: [
                    'required' => self::DOCKER_COMPATIBLE_PROVIDER,
                    'actual' => $containerProvider,
                ],
            );
        }

        return null;
    }

    private function runtimeUserFailure(string $tool, Node $node): ?ToolRegistryFailure
    {
        $runtimeUser = $this->catalog->runtimeUser($tool);

        if ($runtimeUser === null) {
            return null;
        }

        $isolation = $this->catalog->isolation($tool);
        $result = $this->scripts->runForRegistry(
            node: $node,
            tool: $tool,
            action: 'preflight',
            script: $this->runtimeUserProbeScript($runtimeUser, $isolation),
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if ($result->successful()) {
            return null;
        }

        if ($result->exitCode === 65) {
            return $this->remoteConstraintFailure(
                tool: $tool,
                node: $node,
                constraint: 'isolation',
                result: $result,
                context: [
                    'required' => self::UNPRIVILEGED_USER_ISOLATION,
                    'actual' => 'privileged-user',
                ],
            );
        }

        return $this->remoteConstraintFailure(
            tool: $tool,
            node: $node,
            constraint: 'runtime_user',
            result: $result,
            context: [
                'required' => $runtimeUser,
                'actual' => $result->exitCode === 64 ? 'missing' : 'unverified',
            ],
        );
    }

    private function containerProviderFailure(string $tool, Node $node): ?ToolRegistryFailure
    {
        $provider = $this->catalog->requiredContainerProvider($tool);

        if ($provider === null) {
            return null;
        }

        if ($provider !== self::DOCKER_COMPATIBLE_PROVIDER) {
            return ToolRegistryFailure::constraintUnsatisfied(
                tool: $tool,
                node: $node->name,
                constraint: 'container_provider',
                context: [
                    'required' => $provider,
                    'actual' => 'unsupported-provider-declaration',
                ],
            );
        }

        $result = $this->scripts->runForRegistry(
            node: $node,
            tool: $tool,
            action: 'preflight',
            script: self::DOCKER_PROVIDER_PROBE_SCRIPT,
        );

        if ($result instanceof ToolRegistryFailure) {
            return $result;
        }

        if ($result->successful()) {
            return null;
        }

        return $this->remoteConstraintFailure(
            tool: $tool,
            node: $node,
            constraint: 'container_provider',
            result: $result,
            context: [
                'required' => $provider,
                'actual' => match ($result->exitCode) {
                    66 => 'missing',
                    67 => 'unreachable',
                    default => 'unverified',
                },
            ],
        );
    }

    private function runtimeUserProbeScript(string $runtimeUser, ?string $isolation): string
    {
        $runtimeUser = escapeshellarg($runtimeUser);
        $isolationProbe = $isolation === self::UNPRIVILEGED_USER_ISOLATION
            ? <<<'BASH'
                if [ "$orbit_runtime_user_id" -eq 0 ]; then
                    printf '%s\n' 'Required runtime user must be unprivileged.' >&2
                    exit 65
                fi
                BASH
            : '';

        return <<<BASH
            set -eu
            orbit_runtime_user_id="\$(id -u {$runtimeUser} 2>/dev/null)" || {
                printf '%s\n' 'Required runtime user {$runtimeUser} does not exist.' >&2
                exit 64
            }
            {$isolationProbe}
            BASH;
    }

    /** @param array<string, mixed> $context */
    private function remoteConstraintFailure(
        string $tool,
        Node $node,
        string $constraint,
        RemoteShellResult $result,
        array $context,
    ): ToolRegistryFailure {
        return ToolRegistryFailure::constraintUnsatisfied(
            tool: $tool,
            node: $node->name,
            constraint: $constraint,
            context: [
                ...$context,
                'exit_code' => $result->exitCode,
                'stderr' => trim($result->stderr),
            ],
        );
    }
}
