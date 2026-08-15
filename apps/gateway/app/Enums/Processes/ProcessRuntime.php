<?php

declare(strict_types=1);

namespace App\Enums\Processes;

use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Workspaces\WorkspacePlacement;

enum ProcessRuntime: string
{
    case Docker = 'docker';
    case DockerSwarm = 'docker-swarm';
    case Launchd = 'launchd';
    case Systemd = 'systemd';

    public static function defaultForApp(App $app): self
    {
        // App owns no node: the default runtime follows the app's primary
        // concrete instance placement.
        $node = app(WorkspacePlacement::class)->runtimeNode($app, null);

        if ($node instanceof Node && NodeHostPaths::isMacosPlatform($node->platform)) {
            return self::Launchd;
        }

        return self::Systemd;
    }

    public function requiresNodeOwner(): bool
    {
        return match ($this) {
            self::DockerSwarm => true,
            self::Docker, self::Launchd, self::Systemd => false,
        };
    }

    public function nodeOwnerViolationReason(): ?string
    {
        return match ($this) {
            self::DockerSwarm => 'docker_swarm_requires_node_owned_process',
            self::Docker, self::Launchd, self::Systemd => null,
        };
    }

    public function nodeOwnerViolationMessage(): ?string
    {
        return match ($this) {
            self::DockerSwarm => 'The docker-swarm runtime is only valid for node-owned processes.',
            self::Docker, self::Launchd, self::Systemd => null,
        };
    }

    public function appWorkspaceCommandViolationReason(): ?string
    {
        return match ($this) {
            self::Docker => 'docker_runtime_requires_service_or_managed_process',
            self::DockerSwarm => 'docker_swarm_requires_node_owned_process',
            self::Launchd, self::Systemd => null,
        };
    }

    public function appWorkspaceCommandViolationMessage(): ?string
    {
        return match ($this) {
            self::Docker => 'The docker runtime is only valid for managed services or Orbit-managed runtime processes.',
            self::DockerSwarm => $this->nodeOwnerViolationMessage(),
            self::Launchd, self::Systemd => null,
        };
    }
}
