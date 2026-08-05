<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceCreateFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Workspaces\WorkspaceNodeReachability;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use App\Services\Workspaces\WorktreeWorkspaceDriver;
use RuntimeException;

final readonly class CreateWorkspace
{
    public const array SUPPORTED_PHP_VERSIONS = PhpRuntimeCatalog::SUPPORTED;

    public function __construct(
        private SetupWorkspace $setupWorkspace,
        private WorktreeWorkspaceDriver $worktreeDriver,
        private WorkspaceRoleGuard $roleGuard,
        private WorkspaceNodeReachability $nodeReachability,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function handle(
        App $app,
        string $name,
        Instance $instance,
        string $base = 'main',
        ?string $phpVersion = null,
    ): array {
        $node = $this->resolveAppNode($app, $instance);
        $this->ensureSupportedPhpVersion($phpVersion);
        $this->ensureNodeReachable($node);

        $provisionResult = $this->provisionWorkspaceSource($app, $node, $name, $base, $instance);
        $workspace = $this->createIntent($app, $instance, $phpVersion, $provisionResult);

        $warnings = [];
        $httpProbe = [
            'reachable' => false,
            'status' => 'not_run',
        ];

        try {
            $setup = $this->setupWorkspace->handle($app, $workspace, $node);
            $warnings = array_merge($warnings, $setup['warnings']);
            $httpProbe = $setup['http_probe'];
        } catch (RuntimeException $exception) {
            throw new WorkspaceCreateFailed(
                'workspace.enactment_failed',
                "Workspace enactment on node '{$node->name}' stopped before Orbit could classify remaining drift.",
                [
                    'step' => 'setup_pipeline',
                    'node' => $node->name,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        return $this->result($workspace, $app, $node, $base, $httpProbe, $warnings);
    }

    public function resolveAppNode(App $app, Instance $instance): Node
    {
        $app->loadMissing('node');

        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new WorkspaceCreateFailed(
                'workspace.parent_instance_invalid',
                "Instance '{$app->name}.{$instance->name}' does not have an owning app node.",
                [
                    'field' => 'instance',
                    'app' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        try {
            $this->roleGuard->ensureNodeSupportsWorkspaces($app, $node);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            throw new WorkspaceCreateFailed(
                $exception->errorCode(),
                $exception->getMessage(),
                $exception->meta,
            );
        }

        return $node;
    }

    public function ensureSupportedPhpVersion(?string $phpVersion): void
    {
        if ($phpVersion === null || in_array($phpVersion, self::SUPPORTED_PHP_VERSIONS, true)) {
            return;
        }

        throw new WorkspaceCreateFailed(
            'validation_failed',
            'Unsupported PHP version.',
            ['field' => 'php_version', 'reason' => 'unsupported_php_version'],
        );
    }

    public function ensureNodeReachable(Node $node): void
    {
        $this->nodeReachability->ensureReachable($node);
    }

    public function createIntent(
        App $app,
        Instance $instance,
        ?string $phpVersion,
        WorkspaceProvisionResult $provisionResult,
    ): Workspace {
        /** @var Workspace $workspace */
        $workspace = Workspace::create([
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'name' => $provisionResult->name,
            'path' => $provisionResult->path,
            'php_version' => $phpVersion,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $workspace->setRelation('app', $app);
        $workspace->setRelation('instance', $instance);

        return $workspace;
    }

    public function provisionWorkspaceSource(
        App $app,
        Node $node,
        string $name,
        string $base,
        Instance $instance,
    ): WorkspaceProvisionResult {
        $originalPath = $app->path;

        try {
            $instancePath = $this->appPathForInstance($instance);

            if ($instancePath !== null) {
                $app->path = $instancePath;
            }

            return $this->worktreeDriver->create($app, $node, $name, $base);
        } finally {
            $app->path = $originalPath;
        }
    }

    /**
     * @return array{label: string, done_label: string}
     */
    public function sourceProgressLabels(Instance $instance, Node $node): array
    {
        return [
            'label' => 'Creating git worktree',
            'done_label' => 'Git worktree created',
        ];
    }

    /**
     * @param  array{reachable: bool, status: string}  $httpProbe
     * @param  list<array<string, string>>  $warnings
     * @return array{
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     */
    public function result(
        Workspace $workspace,
        App $app,
        Node $node,
        string $base,
        array $httpProbe,
        array $warnings,
    ): array {
        $workspace->refresh();
        $workspace->loadMissing('instance');
        $workspace->setRelation('app', $app);

        return [
            'result' => ['action' => 'created'],
            'workspace' => $this->workspacePayload($workspace, $app, $node),
            'meta' => [
                'node' => $node->name,
                'base' => $base,
                'http_probe' => $httpProbe,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacePayload(Workspace $workspace, App $app, Node $node): array
    {
        return [
            'name' => $workspace->name,
            'app' => $app->name,
            'instance' => $workspace->instance->name,
            'node' => $node->name,
            'path' => $workspace->path,
            'url' => $workspace->url(),
            'php_version' => $workspace->effectivePhpVersion(),
            'php_inherited' => $workspace->php_version === null,
            'adopted' => false,
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];
    }

    private function appPathForInstance(?Instance $instance): ?string
    {
        $config = $instance?->driver_config;

        if (! $config instanceof OrbitInstanceDriverConfigData) {
            return null;
        }

        return is_string($config->path) && $config->path !== '' ? $config->path : null;
    }
}
