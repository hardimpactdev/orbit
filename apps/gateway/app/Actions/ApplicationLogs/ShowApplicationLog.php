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

final readonly class ShowApplicationLog
{
    public function __construct(
        private ApplicationLogPathResolver $paths,
        private RemoteApplicationLogs $remoteLogs,
        private WorkspacePlacement $placement,
        private ApplicationLogStreamTarget $streamTargets,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function forInstance(App $app, Instance $instance, int $lines, ?string $nodeConstraint = null): array
    {
        $node = $this->requireInstanceNode($instance);
        ApplicationLogNodeConstraint::assert($node, $nodeConstraint);
        $paths = $this->paths->forInstance($app, $instance);
        $payload = $this->read($node, $paths, $lines);

        return [
            'data' => $this->envelope(
                type: 'instance',
                app: $app->name,
                instance: $instance->name,
                workspace: null,
                selector: "{$app->name}.{$instance->name}",
                node: $node->name,
                lines: $lines,
                payload: $payload,
            ),
            'meta' => [],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function forWorkspace(Workspace $workspace, int $lines, ?string $nodeConstraint = null): array
    {
        [$app, $instance, $node] = $this->requireWorkspaceContext($workspace);
        ApplicationLogNodeConstraint::assert($node, $nodeConstraint);
        $paths = $this->paths->forWorkspace($workspace);
        $payload = $this->read($node, $paths, $lines);

        return [
            'data' => $this->envelope(
                type: 'workspace',
                app: $app->name,
                instance: $instance->name,
                workspace: $workspace->name,
                selector: $workspace->name,
                node: $node->name,
                lines: $lines,
                payload: $payload,
            ),
            'meta' => [],
        ];
    }

    /**
     * @return array{node: Node, absolute_path: string, authorized_root: string, lines: int, operation_stream: array<string, mixed>}
     */
    public function operationStreamTargetForInstance(
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
    public function operationStreamTargetForWorkspace(
        Workspace $workspace,
        int $lines,
        ?string $nodeConstraint = null,
        ?string $gatewayUrl = null,
    ): array {
        [, , $node] = $this->requireWorkspaceContext($workspace);
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
    public function followTarget(array $target, callable $onOutput): void
    {
        $this->remoteLogs->follow(
            node: $target['node'],
            absolutePath: $target['absolute_path'],
            authorizedRoot: $target['authorized_root'],
            lines: $target['lines'],
            onOutput: $onOutput,
            operationStream: $target['operation_stream'] ?? null,
        );
    }

    /**
     * @param  array{authorized_root: string, absolute_path: string, logical_path: string}  $paths
     * @return array{file_exists: bool, lines: list<mixed>}
     */
    private function read(Node $node, array $paths, int $lines): array
    {
        if ($lines < 1) {
            throw new GatewayApiException('The lines value must be a positive integer.', 'validation_failed', [
                'field' => 'lines',
                'value' => $lines,
            ]);
        }

        $result = $this->remoteLogs->read(
            node: $node,
            absolutePath: $paths['absolute_path'],
            authorizedRoot: $paths['authorized_root'],
            lines: $lines,
        );

        if (! $result->successful()) {
            throw new GatewayApiException(
                'The runtime backend could not read the application log.',
                'application_log.read_failed',
                ['path' => ApplicationLogPathResolver::LogicalPath],
            );
        }

        return $this->decodePayload($result->stdout);
    }

    /**
     * @param  array{file_exists: bool, lines: list<mixed>}  $payload
     * @return array<string, mixed>
     */
    private function envelope(
        string $type,
        string $app,
        string $instance,
        ?string $workspace,
        string $selector,
        string $node,
        int $lines,
        array $payload,
    ): array {
        return [
            'target' => [
                'type' => $type,
                'app' => $app,
                'instance' => $instance,
                'workspace' => $workspace,
                'selector' => $selector,
            ],
            'node' => $node,
            'path' => ApplicationLogPathResolver::LogicalPath,
            'lines_requested' => $lines,
            'file_exists' => $payload['file_exists'],
            'lines' => $payload['lines'],
        ];
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

    /**
     * @return array{0: App, 1: Instance, 2: Node}
     */
    private function requireWorkspaceContext(Workspace $workspace): array
    {
        $workspace->loadMissing(['app', 'instance']);
        $app = $workspace->app;
        $instance = $this->placement->instanceForWorkspace($workspace);

        if (! $app instanceof App || ! $instance instanceof Instance) {
            throw new GatewayApiException(
                'The workspace parent instance could not be resolved.',
                'validation_failed',
                ['field' => 'workspace', 'workspace' => $workspace->name],
            );
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                'The workspace serving node could not be resolved.',
                'validation_failed',
                ['field' => 'workspace'],
            );
        }

        return [$app, $instance, $node];
    }

    /**
     * @return array{file_exists: bool, lines: list<mixed>}
     */
    private function decodePayload(string $stdout): array
    {
        /** @var mixed $payload */
        $payload = json_decode($stdout, associative: true);
        $empty = ['file_exists' => false, 'lines' => []];

        if (! is_array($payload) || ! is_array($payload['success'] ?? null)) {
            return $empty;
        }

        $data = $payload['success']['data'] ?? null;

        if (! is_array($data)) {
            return $empty;
        }

        return [
            'file_exists' => (bool) ($data['file_exists'] ?? false),
            'lines' => is_array($data['lines'] ?? null) ? array_values($data['lines']) : [],
        ];
    }
}
