<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Actions\Processes\RecordProcessEvent;
use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\NodeProcessResolver;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessServiceRehydrator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 * @mago-expect lint:kan-defect
 */
final readonly class DoctorProcessRestorer
{
    private const array WORKSPACE_PROCESS_OWNER_TYPES = [Workspace::class, 'workspace'];

    public function __construct(
        private NodeProcessResolver $nodeProcesses,
        private ProcessRuntimeDriverRegistry $processRuntimeDrivers,
        private DoctorProcessExtraRuntimeRemover $processExtraRuntimeRemover,
        private DoctorManagedFrankenPhpRuntimeRestorer $managedFrankenPhpRuntimeRestorer,
        private ProcessServiceRehydrator $processServiceRehydrator,
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
            return $this->managedFrankenPhpRuntimeRestorer->restoreMissingProcess($node, $key, $detail);
        }

        $process = $this->processFromIssueDetail($node, $detail);

        if (! $process instanceof Process) {
            return null;
        }

        $managedRuntimeAction = $this->managedFrankenPhpRuntimeRestorer->restoreManagedRuntime(
            $node,
            $key,
            $process,
        );

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

        return $this->managedFrankenPhpRuntimeRestorer->restoreAppProcessRuntimeUnits(
            $node,
            $key,
            $process,
            $app,
        );
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
            $resolved = $this->processServiceRehydrator->resolve($process, $node);

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
            return ProcessOwnerContext::forNode($node);
        }

        if ($process->owner instanceof App) {
            if (! $process->instance instanceof Instance) {
                return null;
            }

            return ProcessOwnerContext::forInstance($node, $process->instance);
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

            return ProcessOwnerContext::forWorkspace($node, $process->owner, $process->instance);
        }

        return null;
    }
}
