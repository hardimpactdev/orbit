<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\Tools\ToolCatalog;
use App\Services\WebSockets\WebSocketCertificateInstaller;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerManager;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use RuntimeException;

class WebSocketRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly WebSocketRuntimeContainerRenderer $runtimeRenderer,
        private readonly WebSocketRuntimeContainerManager $runtimeManager,
        private readonly WebSocketCertificateInstaller $certificateInstaller,
        private readonly WebSocketRuntimeSourceInstaller $sourceInstaller,
        private readonly ?RemoteLocalExecutor $localExecutor = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?WebSocketRoleBaselineTiming $timing = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The websocket role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The websocket role requires an Ubuntu host.');
        }

        if (! is_string($node->host) || trim($node->host) === '') {
            throw new RuntimeException('The websocket role requires a reachable host record.');
        }

        $this->timer()->measure('tools', fn () => $this->convergeTools($node, ['docker']));
        $selfContainedImage = $this->timer()->measure('image', fn (): bool => $this->runtimeImageIsSelfContained(
            $node,
        ));
        $appKey = $selfContainedImage
            ? $this->timer()->measure('env', fn (): string => $this->ensureSelfContainedAppKey($node))
            : null;
        $container = $this->timer()->measure(
            'render',
            fn (): WebSocketRuntimeContainer => $this->runtimeContainerFor(
                $node,
                $assignment,
                $selfContainedImage,
                $appKey,
            ),
        );
        $this->timer()->measure('certificates', fn () => $this->certificateInstaller->ensureFor($node));

        if (! $selfContainedImage) {
            $this->timer()->measure('source-install', fn () => $this->sourceInstaller->install($node));
        }

        $this->timer()->measure('container-apply', fn () => $this->runtimeManager->apply($node, $container));
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $containerName = $this->runtimeRenderer->containerName($node);

        if ($this->runtimeManager->remove($node, $containerName)) {
            return;
        }

        throw new RuntimeException("Failed to remove websocket runtime container '{$containerName}' on {$node->name}.");
    }

    private function runtimeContainerFor(
        Node $node,
        NodeRoleAssignment $assignment,
        bool $selfContainedImage,
        ?string $appKey,
    ): WebSocketRuntimeContainer {
        $settings = WebSocketRoleSettings::fromArray($assignment->settings ?? []);

        return $this->runtimeRenderer->render(
            node: $node,
            settings: $settings,
            sourcePath: $selfContainedImage ? null : WebSocketRuntimeContainer::SourceHostPath,
            appKey: $appKey,
        );
    }

    private function runtimeImageIsSelfContained(Node $node): bool
    {
        $result = $this->runWebSocketRuntimeAction(
            node: $node,
            action: 'image:is-self-contained',
        );

        if (! $result->successful()) {
            return false;
        }

        return RemoteShellSuccessData::fromJsonEnvelope($result)['self_contained'] === true;
    }

    private function ensureSelfContainedAppKey(Node $node): string
    {
        $result = $this->runWebSocketRuntimeAction(
            node: $node,
            action: 'app-key:ensure',
        );

        $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        /** @var mixed $appKey */
        $appKey = $data['app_key'] ?? null;

        if (! $result->successful() || ! is_string($appKey) || trim($appKey) === '') {
            throw new RuntimeException("Could not prepare websocket runtime app key on {$node->name}.");
        }

        return trim($appKey);
    }

    private function runWebSocketRuntimeAction(Node $node, string $action): RemoteShellResult
    {
        return $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:websocket-runtime',
            arguments: [$action],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "websocket-runtime.{$action}",
                ],
                'timeout' => 30,
                'throw' => false,
            ],
        );
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function timer(): WebSocketRoleBaselineTiming
    {
        return $this->timing ?? app(WebSocketRoleBaselineTiming::class);
    }
}
