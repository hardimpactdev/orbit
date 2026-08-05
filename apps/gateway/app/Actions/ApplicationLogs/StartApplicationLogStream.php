<?php

declare(strict_types=1);

namespace App\Actions\ApplicationLogs;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\ApplicationLogs\ApplicationLogNodeConstraint;
use App\Services\ApplicationLogs\ApplicationLogPathResolver;
use App\Services\ApplicationLogs\ApplicationLogStreamTarget;
use App\Services\ApplicationLogs\RemoteApplicationLogs;
use App\Services\Workspaces\WorkspacePlacement;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class StartApplicationLogStream
{
    public function __construct(
        private ApplicationLogPathResolver $paths,
        private ApplicationLogStreamTarget $streamTargets,
        private WorkspacePlacement $placement,
        private RemoteApplicationLogs $remoteLogs,
    ) {}

    /**
     * @return array{node: Node, absolute_path: string, authorized_root: string, lines: int, operation_stream: array<string, mixed>}
     */
    public function forInstance(
        App $app,
        Instance $instance,
        int $lines,
        ?string $nodeConstraint = null,
        ?string $gatewayUrl = null,
    ): array {
        $node = $this->requireInstanceNode($instance);
        ApplicationLogNodeConstraint::assert($node, $nodeConstraint);

        return $this->streamTargets->create(
            $node,
            $this->paths->forInstance($app, $instance),
            $lines,
            $gatewayUrl,
            'application.logs.follow.instance',
        );
    }

    /**
     * @return array{node: Node, absolute_path: string, authorized_root: string, lines: int, operation_stream: array<string, mixed>}
     */
    public function forWorkspace(
        Workspace $workspace,
        int $lines,
        ?string $nodeConstraint = null,
        ?string $gatewayUrl = null,
    ): array {
        $node = $this->requireWorkspaceNode($workspace);
        ApplicationLogNodeConstraint::assert($node, $nodeConstraint);

        return $this->streamTargets->create(
            $node,
            $this->paths->forWorkspace($workspace),
            $lines,
            $gatewayUrl,
            'application.logs.follow.workspace',
        );
    }

    /**
     * @param  array{node: Node, absolute_path: string, authorized_root: string, lines: int, operation_stream?: array<string, mixed>}  $target
     * @param  callable(string): void  $onOutput
     */
    public function follow(array $target, callable $onOutput): void
    {
        $this->remoteLogs->follow(
            $target['node'],
            [
                'absolute_path' => $target['absolute_path'],
                'authorized_root' => $target['authorized_root'],
                'lines' => $target['lines'],
            ],
            $onOutput,
            $target['operation_stream'] ?? null,
        );
    }

    private function requireInstanceNode(Instance $instance): Node
    {
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                'The instance serving node could not be resolved.',
                'validation_failed',
                ['field' => 'instance'],
            );
        }

        return $node;
    }

    private function requireWorkspaceNode(Workspace $workspace): Node
    {
        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                'The workspace serving node could not be resolved.',
                'validation_failed',
                ['field' => 'workspace'],
            );
        }

        return $node;
    }
}
