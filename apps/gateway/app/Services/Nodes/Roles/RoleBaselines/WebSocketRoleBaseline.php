<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\WebSockets\WebSocketCertificateInstaller;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerManager;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use RuntimeException;

class WebSocketRoleBaseline implements RoleBaseline
{
    public function __construct(
        private readonly WebSocketRuntimeContainerRenderer $runtimeRenderer,
        private readonly WebSocketRuntimeContainerManager $runtimeManager,
        private readonly WebSocketCertificateInstaller $certificateInstaller,
        private readonly WebSocketRuntimeSourceInstaller $sourceInstaller,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
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

        $container = $this->runtimeContainerFor($node, $assignment);

        $this->certificateInstaller->ensureFor($node);
        $this->sourceInstaller->install($node);
        $this->runtimeManager->apply($node, $container);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $containerName = $this->runtimeRenderer->containerName($node);

        if ($this->runtimeManager->remove($node, $containerName)) {
            return;
        }

        throw new RuntimeException("Failed to remove websocket runtime container '{$containerName}' on {$node->name}.");
    }

    private function runtimeContainerFor(Node $node, NodeRoleAssignment $assignment): WebSocketRuntimeContainer
    {
        $settings = WebSocketRoleSettings::fromArray($assignment->settings ?? []);

        return $this->runtimeRenderer->render($node, $settings);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }
}
