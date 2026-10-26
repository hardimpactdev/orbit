<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeWebSocketRuntimeLifecycle implements WebSocketRuntimeLifecycle
{
    public function __construct(
        private RolePrerequisites $prerequisites,
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
        private WebSocketEnvironmentRenderer $environment = new WebSocketEnvironmentRenderer,
        private WebSocketServiceUnitRenderer $unit = new WebSocketServiceUnitRenderer,
        private string $repository = '',
        private string $ref = '',
        private string $installPath = '',
        private int $port = 0,
    ) {}

    public function converge(Node $node, NodeRole $assignment, WebSocketServerCredentials $credentials): void
    {
        $this->address($node);
        $account = $this->accounts->resolve($node);

        $source = $this->prerequisites->caddy($node, RoleName::WebSocket);
        if ($source !== null) {
            if (! $source->succeeded()) {
                throw new NodeRoleOperationException(
                    'caddy-package-source',
                    'node_role.convergence_failed',
                    'websocket.prerequisite_failed',
                    "The Caddy package step failed on node [{$node->name}].",
                    $source,
                );
            }
        }

        $prerequisites = $this->prerequisites->install($node, RoleName::WebSocket, $account);

        if (! $prerequisites->succeeded()) {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'websocket.prerequisite_failed',
                "WebSocket role prerequisites failed on node [{$node->name}].",
                $prerequisites,
            );
        }

        $port = $this->resolvedPort();
        $envContents = $this->environment->render($credentials, $port);
        $unitContents = $this->unit->render(
            $this->resolvedInstallPath(),
            $account->user,
            $account->group,
            $port,
        );

        try {
            $this->agents->run($node, 'websocket.converge-runtime', [
                'user' => $account->user,
                'group' => $account->group,
                'install_path' => $this->resolvedInstallPath(),
                'repository' => $this->resolvedRepository(),
                'ref' => $this->resolvedRef(),
                'port' => $port,
                'environment' => $envContents,
                'unit' => $unitContents,
            ]);
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new NodeRoleOperationException(
                'websocket-runtime',
                'node_role.convergence_failed',
                'websocket.runtime_convergence_failed',
                "WebSocket role convergence failed on node [{$node->name}].",
                $exception instanceof AgentCommandRefused ? AgentStep::output($exception) : $exception->result,
            );
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->address($node);

        try {
            $this->agents->run($node, 'websocket.remove-runtime', ['install_path' => $this->resolvedInstallPath(), 'purge' => $purgeData]);
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new NodeRoleOperationException(
                'websocket-runtime-removal',
                'node_role.removal_failed',
                'websocket.runtime_removal_failed',
                "WebSocket role removal failed on node [{$node->name}].",
                $exception instanceof AgentCommandRefused ? AgentStep::output($exception) : $exception->result,
            );
        }
    }

    public function health(Node $node): bool
    {
        $this->address($node);

        try {
            return ($this->agents->run($node, 'websocket.runtime-active')['active'] ?? false) === true;
        } catch (AgentCommandRefused|AgentRunnerFailure) {
            return false;
        }
    }

    private function address(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'websocket.wireguard_ip_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return $node->wireguard_ip;
    }

    private function resolvedRepository(): string
    {
        return $this->repository !== '' ? $this->repository : StoredValue::string(config('orbit.websocket.repository'));
    }

    private function resolvedRef(): string
    {
        return $this->ref !== '' ? $this->ref : StoredValue::string(config('orbit.websocket.ref'));
    }

    private function resolvedInstallPath(): string
    {
        return $this->installPath !== '' ? $this->installPath : StoredValue::string(config('orbit.websocket.install_path'));
    }

    private function resolvedPort(): int
    {
        return $this->port > 0 ? $this->port : StoredValue::integer(config('orbit.websocket.port'));
    }
}
