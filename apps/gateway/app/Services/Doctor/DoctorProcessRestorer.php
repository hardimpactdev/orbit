<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Actions\Processes\RecordProcessEvent;
use App\Contracts\SiteCertificateInstaller;
use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Ca\OrbitCaService;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\NodeProcessResolver;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessServiceCatalog;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class DoctorProcessRestorer
{
    private const array WORKSPACE_PROCESS_OWNER_TYPES = [Workspace::class, 'workspace'];

    public function __construct(
        private NodeProcessResolver $nodeProcesses,
        private ProcessRuntimeDriverRegistry $processRuntimeDrivers,
        private DoctorProcessExtraRuntimeRemover $processExtraRuntimeRemover,
        private ProcessServiceCatalog $processServiceCatalog,
        private WorkspacePlacement $workspacePlacement = new WorkspacePlacement,
        private RecordProcessEvent $recordProcessEvent = new RecordProcessEvent,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, string $key, array $detail): ?array
    {
        if (! DoctorProcessRestoreSupport::supports($key)) {
            return null;
        }

        if ($key === 'process.runtime_unit_extra') {
            return $this->processExtraRuntimeRemover->remove($node, $key, $detail);
        }

        if ($key === 'process.runtime_unit_unrenderable') {
            return $this->restoreUnrenderableProcessIssue($node, $key, $detail);
        }

        if (! in_array(
            $key,
            [
                'process.runtime_unit_missing',
                'process.runtime_unit_mismatch',
                'process.runtime_unit_down',
                'process.restart_policy_mismatch',
                'process.runtime_environment_mismatch',
            ],
            true,
        )) {
            return null;
        }

        if (($detail['reason'] ?? null) === 'runtime_process_missing') {
            return $this->restoreMissingFrankenPhpRuntimeProcess($node, $key, $detail);
        }

        $process = $this->processFromIssueDetail($node, $detail);

        if (! $process instanceof Process) {
            return null;
        }

        $managedRuntimeAction = $this->restoreManagedFrankenPhpProcessRuntime($node, $key, $process);

        if ($managedRuntimeAction !== null) {
            return $managedRuntimeAction;
        }

        if ($key === 'process.runtime_unit_down') {
            return $this->startAlwaysOnProcessRuntime($node, $key, $process);
        }

        $app = $process->ownerApp();

        if (! $app instanceof App) {
            return $this->applyNodeOwnedProcessIssue($node, $key, $process);
        }

        $process->loadMissing('instance');
        $instance = $process->instance;

        if (! $instance instanceof Instance) {
            return null;
        }

        try {
            $this->refreshManagedFrankenPhpProcessIntent($process);
            $warnings = app(EnsureAppProcessRuntimeUnits::class)->handle($app, $instance);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if ($warnings !== []) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Process runtime restore for {$app->name}.{$instance->name} completed with warnings.",
                'details' => [
                    'warnings' => $warnings,
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored process runtime units for {$app->name}.{$instance->name}.",
            'details' => [
                'app' => $app->name,
                'instance' => $instance->name,
                'process' => $process->name,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function issueTargetsWorkspace(Node $node, array $detail): bool
    {
        if (is_string($detail['workspace'] ?? null)) {
            return true;
        }

        $processName = is_string($detail['process'] ?? null) ? $detail['process'] : null;

        if ($processName === null) {
            return false;
        }

        /** @var Builder<Process> $query */
        $query = Process::query();
        $query
            ->where('node_id', $node->id)
            ->where('name', $processName)
            ->whereIn('owner_type', self::WORKSPACE_PROCESS_OWNER_TYPES);
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $appInstanceName = is_string($detail['instance'] ?? null) ? $detail['instance'] : null;

        if ($appName !== null && $appInstanceName !== null) {
            $query->whereHas(
                'instance',
                fn (Builder $instanceQuery): Builder => $instanceQuery
                    ->where('name', $appInstanceName)
                    ->whereHas(
                        'app',
                        fn (Builder $appQuery): Builder => $appQuery->where('name', $appName),
                    ),
            );
        }

        /** @var Collection<int, Process> $processes */
        $processes = $query->with('instance.app')->get();
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? $detail['runtime_unit'] : null;

        if ($runtimeUnit === null) {
            return $processes->isNotEmpty();
        }

        foreach ($processes as $process) {
            if ($process->owner_type !== Workspace::class) {
                return true;
            }

            $process->loadMissing('owner');

            if ($this->runtimeUnitNameForProcess($node, $process) === $runtimeUnit) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function startAlwaysOnProcessRuntime(Node $node, string $key, Process $process): ?array
    {
        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext) {
            return null;
        }

        $runtimeApp = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->processRuntimeDrivers->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);
        $this->recordProcessEvent->handle(
            ProcessEventType::Starting,
            $context->eventApp(),
            $workspace,
            $process,
            $node,
            $runtimeUnit,
        );

        try {
            $started = $driver->start($node, $runtimeUnit);
        } catch (\Throwable $exception) {
            $this->recordProcessEvent->handle(
                ProcessEventType::Failed,
                $context->eventApp(),
                $workspace,
                $process,
                $node,
                $runtimeUnit,
            );

            throw $exception;
        }

        $this->recordProcessEvent->handle(
            $started ? ProcessEventType::Started : ProcessEventType::Failed,
            $context->eventApp(),
            $workspace,
            $process,
            $node,
            $runtimeUnit,
        );

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => $started ? 'completed' : 'failed',
            'summary' => $started
                ? "Started always-on process runtime unit {$runtimeUnit}."
                : "Failed to start always-on process runtime unit {$runtimeUnit}.",
            'details' => [
                'node' => $node->name,
                'process' => $process->name,
                'runtime_unit' => $runtimeUnit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function restoreMissingFrankenPhpRuntimeProcess(Node $node, string $key, array $detail): ?array
    {
        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $instanceName = is_string($detail['instance'] ?? null) ? $detail['instance'] : null;

        if ($appName === null || $instanceName === null) {
            return null;
        }

        $app = App::query()
            ->with('instances')
            ->where('name', $appName)
            ->first();
        $instance = $app instanceof App
            ? $app->instances->firstWhere('name', $instanceName)
            : null;

        if (
            ! $app instanceof App
            || ! $instance instanceof Instance
            || $this->workspacePlacement->nodeForInstance($instance)?->id !== $node->id
        ) {
            return null;
        }

        try {
            $process = app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app, $instance);
        } catch (Throwable $exception) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    ...$detail,
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        return $this->restoreManagedFrankenPhpAppRuntime($node, $key, $process, $app);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreManagedFrankenPhpProcessRuntime(Node $node, string $key, Process $process): ?array
    {
        $process->loadMissing('owner');

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? trim($config['container_spec_hash_label'])
            : '';

        if ($hashLabel === '') {
            return null;
        }

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof App) {
            return $this->restoreManagedFrankenPhpAppRuntime($node, $key, $process, $process->owner);
        }

        if ($hashLabel === WorkspaceRuntimeContainer::SpecHashLabel && $process->owner instanceof Workspace) {
            return $this->restoreManagedFrankenPhpWorkspaceRuntime($node, $key, $process, $process->owner);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreManagedFrankenPhpAppRuntime(Node $node, string $key, Process $process, App $app): array
    {
        $process->loadMissing('instance');
        $instance = $process->instance;
        $instanceNode = $instance instanceof Instance
            ? $this->workspacePlacement->nodeForInstance($instance)
            : null;

        if (
            ! $instance instanceof Instance
            || $instance->app_id !== $app->id
            || ! $instanceNode instanceof Node
            || $instanceNode->id !== $node->id
        ) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'instance' => $instance?->name,
                    'process' => $process->name,
                    'error' => 'Process instance has no active serving node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app, $instance);
            $runtimeApp = app(AppRuntimeContainerRenderer::class)->runtimeAppForInstance($app, $instance);
            $this->ensureAppRuntimeTlsMaterial($runtimeApp, $instanceNode);

            $container = app(AppRuntimeContainerRenderer::class)->renderForInstance($app, $instance);
            $outcome = $this->appRuntimeContainerManagerForAgentPush()->apply($instanceNode, $container);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'process' => $process->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored managed FrankenPHP runtime container for {$app->name}.{$instance->name}.",
            'details' => [
                'app' => $app->name,
                'instance' => $instance->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    private function appRuntimeContainerManagerForAgentPush(): AppRuntimeContainerManager
    {
        return new AppRuntimeContainerManager(
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
            app(AppDevelopmentInnerTlsPolicy::class),
            localExecutor: app(RemoteLocalExecutor::class),
        );
    }

    private function workspaceRuntimeContainerManagerForAgentPush(): WorkspaceRuntimeContainerManager
    {
        return new WorkspaceRuntimeContainerManager(
            app(DockerCommandBuilder::class),
            app(OrbitCaService::class),
            app(AppDevelopmentInnerTlsPolicy::class),
            localExecutor: app(RemoteLocalExecutor::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function restoreManagedFrankenPhpWorkspaceRuntime(
        Node $node,
        string $key,
        Process $process,
        Workspace $workspace,
    ): array {
        $workspace->loadMissing(['app.node', 'instance']);
        $app = $workspace->app;
        $workspaceNode = $this->workspacePlacement->nodeForWorkspace($workspace);

        if (! $app instanceof App || ! $workspaceNode instanceof Node || $workspaceNode->id !== $node->id) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'workspace' => $workspace->name,
                    'process' => $process->name,
                    'error' => 'Process workspace has no active parent app node.',
                ],
            ];
        }

        try {
            app(EnsureFrankenPhpRuntimeProcess::class)->forWorkspace($workspace);
            $this->ensureWorkspaceRuntimeTlsMaterial($workspace, $workspaceNode);

            $container = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace);
            $outcome = $this->workspaceRuntimeContainerManagerForAgentPush()->apply($workspaceNode, $container);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $workspaceNode instanceof Node ? $workspaceNode->name : $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'app' => $app->name,
                    'workspace' => $workspace->name,
                    'process' => $process->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $workspaceNode->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored managed FrankenPHP runtime container for workspace {$workspace->name}.",
            'details' => [
                'app' => $app->name,
                'workspace' => $workspace->name,
                'process' => $process->name,
                'container' => $container->name(),
                'outcome' => $outcome->value,
            ],
        ];
    }

    private function refreshManagedFrankenPhpProcessIntent(Process $process): void
    {
        $process->loadMissing(['owner', 'instance']);

        $config = $process->runtime_config;
        $hashLabel = is_string($config['container_spec_hash_label'] ?? null)
            ? $config['container_spec_hash_label']
            : null;

        if ($hashLabel === null || trim($hashLabel) === '') {
            return;
        }

        $ensureFrankenPhpRuntimeProcess = app(EnsureFrankenPhpRuntimeProcess::class);

        if ($hashLabel === AppRuntimeContainer::SpecHashLabel && $process->owner instanceof App) {
            if (! $process->instance instanceof Instance) {
                throw new RuntimeException(
                    'A concrete instance is required to refresh FrankenPHP process intent.',
                );
            }

            $ensureFrankenPhpRuntimeProcess->forApp($process->owner, $process->instance);

            return;
        }

        if ($hashLabel === WorkspaceRuntimeContainer::SpecHashLabel && $process->owner instanceof Workspace) {
            $ensureFrankenPhpRuntimeProcess->forWorkspace($process->owner);
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function restoreUnrenderableProcessIssue(Node $node, string $key, array $detail): ?array
    {
        $process = $this->processFromIssueDetail($node, $detail);
        $service = is_string($detail['service'] ?? null) ? $detail['service'] : null;
        $version = is_string($detail['version'] ?? null)
            ? $detail['version']
            : (is_string($detail['version_family'] ?? null) ? $detail['version_family'] : null);

        if (! $process instanceof Process || $service === null) {
            return null;
        }

        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext || ! $context->owner instanceof Node) {
            return null;
        }

        try {
            $resolved = $this->processServiceCatalog->resolve(
                service: $service,
                version: $version,
                runtime: $process->runtime,
                node: $node,
                processName: $process->name,
            );

            $process->forceFill([
                'command' => $resolved->command,
                'runtime_config' => $resolved->runtimeConfig,
            ])->save();

            $process->refresh();
            $action = $this->applyNodeOwnedProcessIssue($node, $key, $process);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'node' => $node->name,
                    'process' => $process->name,
                    'service' => $service,
                    'version' => $version,
                    'runtime' => $process->runtime->value,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if ($action === null) {
            return null;
        }

        $details = is_array($action['details'] ?? null) ? $action['details'] : [];
        $action['details'] = [
            ...$details,
            'service' => $service,
            'version' => $process->runtime_config['version'] ?? $version,
            'runtime' => $process->runtime->value,
        ];

        if (($action['status'] ?? null) === 'completed') {
            $action['summary'] = "Restored managed service runtime config for process {$process->name}.";
        }

        return $action;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function applyNodeOwnedProcessIssue(Node $node, string $key, Process $process): ?array
    {
        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext || ! $context->owner instanceof Node) {
            return null;
        }

        try {
            $runtimeApp = $context->runtimeApp();
            $workspace = $context->runtimeWorkspaceFor($process);
            $driver = $this->processRuntimeDrivers->forProcess($process);
            $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);
            $restored = $driver->apply($node, $runtimeApp, $process, $workspace);
        } catch (Throwable $e) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore {$key}.",
                'details' => [
                    'node' => $node->name,
                    'process' => $process->name,
                    'error' => $e->getMessage(),
                ],
            ];
        }

        if (! $restored) {
            return [
                'family' => 'process',
                'node' => $node->name,
                'code' => $key,
                'key' => $key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to restore process runtime unit {$runtimeUnit}.",
                'details' => [
                    'node' => $node->name,
                    'process' => $process->name,
                    'runtime_unit' => $runtimeUnit,
                ],
            ];
        }

        return [
            'family' => 'process',
            'node' => $node->name,
            'code' => $key,
            'key' => $key,
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => "Restored process runtime unit {$runtimeUnit}.",
            'details' => [
                'node' => $node->name,
                'process' => $process->name,
                'runtime_unit' => $runtimeUnit,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function processFromIssueDetail(Node $node, array $detail): ?Process
    {
        $processName = is_string($detail['process'] ?? null) ? $detail['process'] : null;

        if ($processName === null) {
            return null;
        }

        $appName = is_string($detail['app'] ?? null) ? $detail['app'] : null;
        $appInstanceName = is_string($detail['instance'] ?? null) ? $detail['instance'] : null;

        if (($appName === null) !== ($appInstanceName === null)) {
            return null;
        }

        /** @var Collection<int, Process> $processes */
        $processes = $this->nodeProcesses
            ->forNode($node, ['owner', 'instance.app'])
            ->filter(static fn (Process $process): bool => $process->name === $processName);

        if ($appName !== null && $appInstanceName !== null) {
            $processes = $processes->filter(static function (Process $process) use ($appName, $appInstanceName): bool {
                $instance = $process->instance;

                return (
                    $instance instanceof Instance
                    && $instance->name === $appInstanceName
                    && $instance->app?->name === $appName
                );
            });
        }

        /** @var Collection<int, Process> $processes */
        $processes = $processes->values();
        $runtimeUnit = is_string($detail['runtime_unit'] ?? null) ? $detail['runtime_unit'] : null;
        $runtimeProcess = $this->processForRuntimeUnit($node, $processes, $runtimeUnit);

        if ($runtimeProcess instanceof Process) {
            return $runtimeProcess;
        }

        if ($processes->count() !== 1) {
            return null;
        }

        $process = $processes->first();

        return $process instanceof Process ? $process : null;
    }

    /**
     * @param  Collection<int, Process>  $processes
     */
    private function processForRuntimeUnit(Node $node, Collection $processes, ?string $runtimeUnit): ?Process
    {
        if ($runtimeUnit === null) {
            return null;
        }

        return $processes->first(
            fn (Process $process): bool => $this->runtimeUnitNameForProcess($node, $process) === $runtimeUnit,
        );
    }

    private function runtimeUnitNameForProcess(Node $node, Process $process): ?string
    {
        $context = $this->processOwnerContext($node, $process);

        if (! $context instanceof ProcessOwnerContext) {
            return null;
        }

        try {
            $driver = $this->processRuntimeDrivers->forProcess($process);

            return $driver->runtimeUnitName($context->runtimeApp(), $process, $context->runtimeWorkspaceFor($process));
        } catch (Throwable) {
            return null;
        }
    }

    private function processOwnerContext(Node $node, Process $process): ?ProcessOwnerContext
    {
        $process->loadMissing(['owner', 'instance']);

        if ($process->owner instanceof Node) {
            return new ProcessOwnerContext(
                node: $node,
                app: null,
                workspace: null,
                owner: $process->owner,
            );
        }

        if ($process->owner instanceof App) {
            if (! $process->instance instanceof Instance) {
                return null;
            }

            return new ProcessOwnerContext(
                node: $node,
                app: $process->owner,
                workspace: null,
                owner: $process->owner,
                instance: $process->instance,
            );
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing(['app', 'instance']);

            if (
                ! $process->owner->app instanceof App
                || ! $process->instance instanceof Instance
                || ! $process->owner->instance instanceof Instance
                || ! $process->instance->is($process->owner->instance)
            ) {
                return null;
            }

            return new ProcessOwnerContext(
                node: $node,
                app: $process->owner->app,
                workspace: $process->owner,
                owner: $process->owner,
                instance: $process->instance,
            );
        }

        return null;
    }

    private function ensureAppRuntimeTlsMaterial(App $app, Node $node): void
    {
        $innerTlsPolicy = app(AppDevelopmentInnerTlsPolicy::class);

        if (! $innerTlsPolicy->appliesToApp($app)) {
            return;
        }

        app(SiteCertificateInstaller::class)->ensureFor(
            $node,
            $innerTlsPolicy->appRouteDomain($app),
        );
    }

    private function ensureWorkspaceRuntimeTlsMaterial(Workspace $workspace, Node $node): void
    {
        $innerTlsPolicy = app(AppDevelopmentInnerTlsPolicy::class);

        if (! $innerTlsPolicy->appliesToWorkspace($workspace)) {
            return;
        }

        app(SiteCertificateInstaller::class)->ensureFor(
            $node,
            $innerTlsPolicy->workspaceRouteDomain($workspace),
        );
    }
}
