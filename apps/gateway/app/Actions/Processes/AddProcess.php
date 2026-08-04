<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessEventType;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orbit\Sdk\Laravel\GatewayApiException;

/** @mago-expect lint:too-many-methods */
final readonly class AddProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private ProcessServiceCatalog $serviceCatalog,
        private RecordProcessEvent $recordProcessEvent,
    ) {}

    /**
     * @param  array<string, mixed>  $serviceOptions
     * @param  list<string>  $replaceContainers
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(
        ProcessOwnerContext $context,
        string $name,
        ?string $command,
        ProcessRestartPolicy $restartPolicy,
        ProcessCrashNotification $crashNotification,
        bool $start,
        ?ProcessRuntime $runtime = null,
        ?string $tool = null,
        ?string $service = null,
        ?string $version = null,
        ?string $image = null,
        array $serviceOptions = [],
        array $replaceContainers = [],
        ?Node $consumer = null,
        ?string $label = null,
    ): array {
        $app = $context->runtimeApp();
        $app->loadMissing(['node', 'workspaces']);

        $resolvedRuntime = $runtime ?? ($service === null ? $context->defaultRuntime() : ProcessRuntime::Docker);
        $runtimeConfig = [];
        $replaceContainers = $this->normalizedReplacementContainers($replaceContainers);

        if ($image !== null && $service === null) {
            throw new GatewayApiException('Process service image requires a managed service.', 'validation_failed', [
                'field' => 'image',
                'value' => $image,
                'reason' => 'process_service_image_requires_service',
            ]);
        }

        if ($image !== null && $resolvedRuntime === ProcessRuntime::Systemd) {
            throw new GatewayApiException(
                'Process service image overrides require a Docker runtime.',
                'validation_failed',
                [
                    'field' => 'image',
                    'value' => $image,
                    'reason' => 'process_service_image_requires_docker_runtime',
                ],
            );
        }

        if ($service !== null) {
            if (! $context->owner instanceof Node) {
                throw new GatewayApiException(
                    'Managed services are only valid for node-owned service processes.',
                    'validation_failed',
                    [
                        'field' => 'service',
                        'value' => $service,
                        'reason' => 'process_service_requires_node_owned_process',
                    ],
                );
            }

            if ($tool !== null) {
                throw new GatewayApiException('Managed services do not use tool dependencies.', 'validation_failed', [
                    'field' => 'tool',
                    'value' => $tool,
                    'reason' => 'process_service_cannot_reference_tool',
                ]);
            }

            $context->assertRuntimeAllowed($resolvedRuntime);

            $serviceDescriptor = $this->serviceCatalog->resolve(
                service: $service,
                version: $version,
                runtime: $resolvedRuntime,
                node: $context->node,
                processName: $name,
                imageOverride: $image,
                serviceOptions: $serviceOptions,
            );

            $command = $serviceDescriptor->command;
            $runtimeConfig = $serviceDescriptor->runtimeConfig;
            $credentials = $serviceDescriptor->credentials;
        } else {
            $context->assertRuntimeAllowed($resolvedRuntime);
            $credentials = [];
        }

        if ($command === null || trim($command) === '') {
            throw new GatewayApiException('The process command is required.', 'validation_failed', [
                'field' => 'command',
            ]);
        }

        if ($context->ownerProcesses()->where('name', $name)->exists()) {
            throw new GatewayApiException(
                "Process '{$name}' already exists for {$context->label()}.",
                'process.name_collision',
                $context->errorMeta($name),
            );
        }

        if ($runtimeConfig !== []) {
            $this->assertServiceHasNoResourceConflicts($context, $name, $runtimeConfig);
        }

        $this->assertReplacementContainersAllowed($context, $resolvedRuntime, $service, $replaceContainers);

        $replacedContainers = $this->removeReplacementContainers($context, $replaceContainers);

        $resolvedLabel = $label ?? $name;

        $process = DB::transaction(function () use (
            $context,
            $name,
            $resolvedLabel,
            $command,
            $restartPolicy,
            $crashNotification,
            $resolvedRuntime,
            $tool,
            $runtimeConfig,
            $credentials,
        ): Process {
            $maxOrder = $context
                ->ownerProcesses()
                ->lockForUpdate()
                ->max('sort_order') ?? 0;

            $process = $context
                ->ownerProcesses()
                ->create([
                    'node_id' => $context->node->id,
                    'app_instance_id' => $context->appInstance?->id,
                    'name' => $name,
                    'label' => $resolvedLabel,
                    'command' => $command,
                    'restart_policy' => $restartPolicy,
                    'crash_notification' => $crashNotification,
                    'runtime' => $resolvedRuntime,
                    'tool' => $tool,
                    'runtime_config' => $runtimeConfig,
                    'credentials' => $credentials !== [] ? $credentials : null,
                    'sort_order' => $maxOrder + 1,
                ]);

            if (! $process instanceof Process) {
                throw new LogicException('Process owner relation created an unexpected model.');
            }

            return $process;
        });

        $app->unsetRelation('processes');
        $runtimeUnits = $this->runtimeUnitPayload->forProcess(
            $app,
            $process,
            $context->runtimeWorkspaceFor($process),
            $consumer,
        );
        $startableRuntimeUnits = $runtimeUnits;

        if ($context->app instanceof Project && $context->workspace === null) {
            $warnings = $this->ensureRuntimeUnits->handle($app, $context->appInstance, $consumer);
        } else {
            $applyResult = $this->applyRuntimeUnits($context, $app, $process, $runtimeUnits);
            $warnings = $applyResult['warnings'];
            $startableRuntimeUnits = $applyResult['applied_runtime_units'];
        }

        if ($start) {
            $warnings = [
                ...$warnings,
                ...$this->startRuntimeUnits($context, $process, $startableRuntimeUnits),
            ];
        }

        return [
            'data' => [
                'process' => [
                    ...$context->processPayload($process),
                ],
                'runtime_units' => $runtimeUnits,
                ...($replacedContainers !== [] ? ['replaced_containers' => $replacedContainers] : []),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, mixed>  $replaceContainers
     * @return list<string>
     */
    private function normalizedReplacementContainers(array $replaceContainers): array
    {
        $containers = [];

        foreach ($replaceContainers as $container) {
            $containers[] = is_string($container) ? trim($container) : '';
        }

        return array_values(array_unique($containers));
    }

    /**
     * @param  list<string>  $replaceContainers
     */
    private function assertReplacementContainersAllowed(
        ProcessOwnerContext $context,
        ProcessRuntime $runtime,
        ?string $service,
        array $replaceContainers,
    ): void {
        if ($replaceContainers === []) {
            return;
        }

        if (! $context->owner instanceof Node || $service === null || $runtime !== ProcessRuntime::Docker) {
            throw new GatewayApiException(
                'Replacement containers are only supported for node-owned Docker managed services.',
                'validation_failed',
                [
                    'field' => 'replace_containers',
                    'reason' => 'replace_container_requires_node_docker_service',
                ],
            );
        }

        foreach ($replaceContainers as $container) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $container) === 1) {
                continue;
            }

            throw new GatewayApiException(
                'Replacement container names must be valid Docker container names.',
                'validation_failed',
                [
                    'field' => 'replace_containers',
                    'value' => $container,
                ],
            );
        }
    }

    /**
     * @param  list<string>  $replaceContainers
     * @return list<string>
     */
    private function removeReplacementContainers(ProcessOwnerContext $context, array $replaceContainers): array
    {
        if ($replaceContainers === []) {
            return [];
        }

        $driver = $this->runtimeDrivers->for(ProcessRuntime::Docker);
        $replaced = [];

        foreach ($replaceContainers as $container) {
            if (! $driver->remove($context->node, $container)) {
                throw new GatewayApiException(
                    "Replacement container '{$container}' could not be removed.",
                    'process.replace_container_failed',
                    [
                        'field' => 'replace_containers',
                        'container' => $container,
                        'node' => $context->node->name,
                    ],
                );
            }

            $replaced[] = $container;
        }

        return $replaced;
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return array{
     *     warnings: list<array<string, mixed>>,
     *     applied_runtime_units: list<array{name: string, context: string}>
     * }
     */
    private function applyRuntimeUnits(
        ProcessOwnerContext $context,
        Project $app,
        Process $process,
        array $runtimeUnits,
    ): array {
        $warnings = [];
        $appliedRuntimeUnits = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $workspace = $context->runtimeWorkspaceFor($process);
            $applied = $driver->apply($context->node, $app, $process, $workspace);

            if (! $applied) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_apply_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$runtimeUnit['name']}' could not be rendered or applied.",
                    'next_command' => 'doctor --family=process --restore',
                ];

                continue;
            }

            $appliedRuntimeUnits[] = $runtimeUnit;
        }

        return [
            'warnings' => $warnings,
            'applied_runtime_units' => $appliedRuntimeUnits,
        ];
    }

    /**
     * Start the rendered runtime units after a successful apply through the
     * process runtime driver selected by `$process->runtime`.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function startRuntimeUnits(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $workspace = $this->workspaceForRuntimeUnit($context, $runtimeUnit);

            $this->recordProcessEvent->handle(
                ProcessEventType::Starting,
                $context->eventApp(),
                $workspace,
                $process,
                $context->node,
                $name,
            );

            try {
                $started = $driver->start($context->node, $name);
            } catch (\Throwable $exception) {
                $this->recordProcessEvent->handle(
                    ProcessEventType::Failed,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $name,
                );

                throw $exception;
            }

            if (! $started) {
                $this->recordProcessEvent->handle(
                    ProcessEventType::Failed,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $name,
                );
                $warnings[] = [
                    'code' => 'process.runtime_unit_start_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be started.",
                    'next_command' => 'doctor --family=process --restore',
                ];

                continue;
            }

            $this->recordProcessEvent->handle(
                ProcessEventType::Started,
                $context->eventApp(),
                $workspace,
                $process,
                $context->node,
                $name,
            );
        }

        return $warnings;
    }

    /**
     * @param  array{name: string, context: string}  $runtimeUnit
     */
    private function workspaceForRuntimeUnit(ProcessOwnerContext $context, array $runtimeUnit): ?Workspace
    {
        if ($context->workspace instanceof Workspace) {
            return $context->workspace;
        }

        $unitContext = $runtimeUnit['context'] ?? null;

        if (! is_string($unitContext) || $unitContext === '' || $unitContext === 'main' || $unitContext === 'node') {
            return null;
        }

        $app = $context->app;

        if (! $app instanceof Project) {
            return null;
        }

        $app->loadMissing('workspaces');

        $workspace = $app->workspaces->first(
            static fn (mixed $candidate): bool => $candidate instanceof Workspace && $candidate->name === $unitContext,
        );

        return $workspace instanceof Workspace ? $workspace : null;
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private function assertServiceHasNoResourceConflicts(
        ProcessOwnerContext $context,
        string $name,
        array $runtimeConfig,
    ): void {
        $requestedEndpoints = $this->endpoints($runtimeConfig);
        $requestedVolumeNames = $this->volumeNames($runtimeConfig);

        $processes = Process::query()
            ->where('node_id', $context->node->id)
            ->get();

        foreach ($processes as $process) {
            $config = is_array($process->runtime_config) ? $process->runtime_config : [];

            foreach ($requestedEndpoints as $endpoint) {
                foreach ($this->endpoints($config) as $existingEndpoint) {
                    if (
                        $endpoint['port'] !== $existingEndpoint['port']
                        || $endpoint['host'] !== $existingEndpoint['host']
                    ) {
                        continue;
                    }

                    throw new GatewayApiException(
                        "Process '{$name}' endpoint port {$endpoint['port']} conflicts with process '{$process->name}'.",
                        'validation_failed',
                        [
                            'field' => 'service',
                            'reason' => 'endpoint_conflict',
                            'node' => $context->node->name,
                            'process' => $name,
                            'existing_process' => $process->name,
                            'host' => $endpoint['host'],
                            'port' => $endpoint['port'],
                        ],
                    );
                }
            }

            foreach ($requestedVolumeNames as $volumeName) {
                if (! in_array($volumeName, $this->volumeNames($config), true)) {
                    continue;
                }

                throw new GatewayApiException(
                    "Process '{$name}' volume '{$volumeName}' conflicts with process '{$process->name}'.",
                    'validation_failed',
                    [
                        'field' => 'service',
                        'reason' => 'volume_conflict',
                        'node' => $context->node->name,
                        'process' => $name,
                        'existing_process' => $process->name,
                        'volume' => $volumeName,
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string|null, host: string, port: int}>
     */
    private function endpoints(array $config): array
    {
        $rawEndpoints = [];

        if (is_array($config['endpoint'] ?? null)) {
            $rawEndpoints[] = $config['endpoint'];
        }

        if (is_array($config['endpoints'] ?? null)) {
            foreach ($config['endpoints'] as $endpoint) {
                if (is_array($endpoint)) {
                    $rawEndpoints[] = $endpoint;
                }
            }
        }

        if (is_array($config['ports'] ?? null)) {
            foreach ($config['ports'] as $port) {
                if (! is_array($port)) {
                    continue;
                }

                $rawEndpoints[] = [
                    'name' => $port['name'] ?? null,
                    'host' => $port['host'] ?? null,
                    'port' => $port['published'] ?? null,
                ];
            }
        }

        $endpoints = [];

        foreach ($rawEndpoints as $endpoint) {
            $port = (int) ($endpoint['port'] ?? 0);
            $host = is_string($endpoint['host'] ?? null) ? trim($endpoint['host']) : '';

            if ($host === '' || $port < 1) {
                continue;
            }

            $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;

            $endpoints[] = [
                'name' => $name !== '' ? $name : null,
                'host' => $host,
                'port' => $port,
            ];
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function volumeNames(array $config): array
    {
        $volumes = [];

        foreach (['mounts', 'volumes'] as $key) {
            if (! is_array($config[$key] ?? null)) {
                continue;
            }

            foreach ($config[$key] as $volume) {
                if (! is_array($volume)) {
                    continue;
                }

                foreach (['name', 'source'] as $nameKey) {
                    $name = is_string($volume[$nameKey] ?? null) ? trim($volume[$nameKey]) : '';

                    if ($name !== '') {
                        $volumes[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($volumes));
    }
}
