<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;
use SensitiveParameter;

final readonly class NativeGatewayPeerProjectionManager implements GatewayPeerProjectionManager
{
    private const string GENERATED_CONFIG_PATH = '/generated/wireguard/orbit.conf';

    private const string LOCK_PATH = '/locks/wireguard-server.lock';

    public function __construct(
        private VpnConfigurationRepository $configuration,
        private WireGuardServerConfigRenderer $serverRenderer,
        private ProtectedFileWriter $files,
        private AgentCommandRunner $agents,
        private string $orbitHome,
    ) {}

    public function converge(Node $node): void
    {
        $this->project($node, null);
    }

    public function remove(Node $node): void
    {
        $this->project($node, $node->id);
    }

    public function restore(Node $node): void
    {
        $this->project($node, null);
    }

    private function project(Node $context, ?int $excludedNodeId): void
    {
        $this->withLock(function () use ($context, $excludedNodeId): void {
            $vpn = $this->configuration->forPeer($context);
            $nodes = Node::query()->whereNotNull('wireguard_public_key');

            if ($excludedNodeId !== null) {
                $nodes->whereKeyNot($excludedNodeId);
            }

            $serverConfig = $this->serverRenderer->render($vpn, $nodes->get());
            $this->files->put($this->path(self::GENERATED_CONFIG_PATH), $serverConfig);
            $this->publish($vpn->server, $serverConfig);
        });
    }

    /**
     * Installs and runs the hub configuration on the `vpn` Node through `wireguard.project-hub`: a local process
     * when that is the Gateway's own Node, SSH to its WireGuard address otherwise.
     */
    private function publish(Node $hub, #[SensitiveParameter] string $config): void
    {
        try {
            $this->agents->run($hub, 'wireguard.project-hub', ['config' => $config]);
        } catch (AgentCommandRefused $refused) {
            [$step, $errorCode] = match ($refused->errorCode) {
                'wireguard.hub_install_failed' => ['wireguard-server-install', 'vpn.server_config_install_failed'],
                'wireguard.hub_config_invalid' => ['wireguard-server-validate', 'vpn.server_config_invalid'],
                default => ['wireguard-server-restart', 'vpn.server_start_failed'],
            };

            throw $this->failure($step, $errorCode, $hub, AgentStep::output($refused), $refused);
        } catch (AgentRunnerFailure $failure) {
            throw $this->failure(
                'wireguard-server-restart',
                'vpn.server_start_failed',
                $hub,
                $failure->result ?? new CommandResult(1, '', $failure->getMessage(), 0, false),
                $failure,
            );
        }
    }

    private function withLock(callable $operation): void
    {
        $lockPath = $this->path(self::LOCK_PATH);
        $directory = dirname($lockPath);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw $this->lockFailure();
        }

        chmod(filename: $directory, permissions: 0o700);
        $lock = fopen(filename: $lockPath, mode: 'c+');

        if ($lock === false) {
            throw $this->lockFailure();
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw $this->lockFailure();
            }

            $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function lockFailure(): NodeProvisioningException
    {
        return new NodeProvisioningException(
            step: 'wireguard-server-lock',
            errorCode: 'vpn.server_lock_failed',
            message: 'Could not converge the gateway WireGuard service.',
        );
    }

    private function failure(string $step, string $errorCode, Node $hub, CommandResult $result, \Throwable $previous): NodeProvisioningException
    {
        return new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: "Could not converge the WireGuard hub on node [{$hub->name}].",
            previous: $previous,
            result: $result,
        );
    }

    private function path(string $suffix): string
    {
        return rtrim(string: $this->orbitHome, characters: '/').$suffix;
    }
}
