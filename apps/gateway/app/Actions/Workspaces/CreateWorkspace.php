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
use App\Support\Streaming\NullProgressReporter;

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
        $result = $this->plan($app, $name, $instance, $base, $phpVersion)->run(new NullProgressReporter);

        if (! $result->isSuccessful()) {
            $failure = $result->failure();

            throw new WorkspaceCreateFailed(
                $failure['code'] ?? 'workspace.enactment_failed',
                $failure['message'] ?? 'Workspace creation failed.',
                $failure['meta'] ?? [],
            );
        }

        return $result->data();
    }

    public function plan(
        App $app,
        string $name,
        Instance $instance,
        string $base = 'main',
        ?string $phpVersion = null,
    ): CreateWorkspacePlan {
        $node = $this->resolveAppNode($app, $instance);
        $this->ensureSupportedPhpVersion($phpVersion);

        return new CreateWorkspacePlan(
            $this,
            $this->setupWorkspace,
            $app,
            $node,
            $name,
            $base,
            $phpVersion,
            $instance,
        );
    }

    public function resolveAppNode(App $app, Instance $instance): Node
    {
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
            'php_version' => $phpVersion ?? $instance->php_version,
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
        // Placement is instance-authoritative: the worktree driver resolves the
        // source path from the instance directly, so no App::path mutation.
        return $this->worktreeDriver->create($app, $node, $name, $base, $instance);
    }

    /**
     * @param  array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int}  $httpProbe
     * @param  list<array<string, mixed>>  $warnings
     * @return array{
     *     result: array{action: 'created'},
     *     workspace: array<string, mixed>,
     *     meta: array<string, mixed>,
     * }
     * @mago-expect lint:excessive-parameter-list
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
            'php_inherited' => false,
            'adopted' => $workspace->adopted,
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];
    }
}
