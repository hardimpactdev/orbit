<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Processes\ProcessRuntimeUnitSpec;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:too-many-methods
 */
final readonly class ProcessRuntimeUnitSpecFactory
{
    public function __construct(
        private ProcessDockerContainerRenderer $dockerContainerRenderer,
        private SystemdUnitRenderer $systemdUnitRenderer,
        private LaunchdPlistRenderer $launchdPlistRenderer,
    ) {}

    public function name(
        ProcessRuntime $runtime,
        App $app,
        Process $process,
        ?Workspace $workspace = null,
    ): string {
        return match ($runtime) {
            ProcessRuntime::Docker => $this->dockerContainerRenderer->containerName($app, $process, $workspace),
            ProcessRuntime::DockerSwarm => $this->dockerSwarmServiceName($process),
            ProcessRuntime::Launchd => $this->launchdPlistRenderer->unitName($app, $process, $workspace),
            ProcessRuntime::Systemd => $this->systemdUnitRenderer->unitName($app, $process, $workspace),
        };
    }

    public function make(
        ProcessRuntime $runtime,
        Node $node,
        App $app,
        Process $process,
        ?Workspace $workspace = null,
    ): ProcessRuntimeUnitSpec {
        return match ($runtime) {
            ProcessRuntime::Docker => $this->docker($app, $process, $workspace),
            ProcessRuntime::DockerSwarm => $this->dockerSwarm($process),
            ProcessRuntime::Launchd => $this->launchd($node, $app, $process, $workspace),
            ProcessRuntime::Systemd => $this->systemd($node, $app, $process, $workspace),
        };
    }

    public function launchdPath(string $runtimeUnit, Node $node): string
    {
        return $this->launchdPlistRenderer->plistPath($runtimeUnit, $node);
    }

    private function docker(
        App $app,
        Process $process,
        ?Workspace $workspace,
    ): ProcessRuntimeUnitSpec {
        $container = $this->dockerContainerRenderer->render($app, $process, $workspace);
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $configuredHash = $config['container_spec_hash'] ?? null;
        $configuredHashLabel = $config['container_spec_hash_label'] ?? null;

        return new ProcessRuntimeUnitSpec(
            name: $container->name(),
            configPath: $container->name(),
            configHash: is_string($configuredHash) && $configuredHash !== ''
                ? $configuredHash
                : $container->specHash(),
            configHashLabel: is_string($configuredHashLabel) && $configuredHashLabel !== ''
                ? $configuredHashLabel
                : ProcessDockerContainer::SpecHashLabel,
            restartPolicy: '',
            environmentLines: [],
        );
    }

    private function dockerSwarm(Process $process): ProcessRuntimeUnitSpec
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $serviceName = $this->dockerSwarmServiceName($process);
        $configuredHash =
            $this->optionalConfigString($config, 'spec_hash') ?? $this->optionalConfigString(
                $this->stringMap($config['labels'] ?? []),
                ProcessDockerContainer::SpecHashLabel,
            ) ?? '';

        return new ProcessRuntimeUnitSpec(
            name: $serviceName,
            configPath: $serviceName,
            configHash: $configuredHash,
            configHashLabel: ProcessDockerContainer::SpecHashLabel,
            restartPolicy: '',
            environmentLines: [],
        );
    }

    private function systemd(
        Node $node,
        App $app,
        Process $process,
        ?Workspace $workspace,
    ): ProcessRuntimeUnitSpec {
        $runtimeUnit = $this->systemdUnitRenderer->unitName($app, $process, $workspace);
        $content = $this->systemdUnitRenderer->render($node, $app, $process, $workspace);

        return new ProcessRuntimeUnitSpec(
            name: $runtimeUnit,
            configPath: $this->systemdUnitRenderer->unitPath($runtimeUnit),
            configHash: hash('sha256', $content),
            configHashLabel: '',
            restartPolicy: $process->restart_policy->toSystemd(),
            environmentLines: $this->environmentLines($content),
        );
    }

    private function launchd(
        Node $node,
        App $app,
        Process $process,
        ?Workspace $workspace,
    ): ProcessRuntimeUnitSpec {
        $runtimeUnit = $this->launchdPlistRenderer->unitName($app, $process, $workspace);
        $content = $this->launchdPlistRenderer->render($node, $app, $process, $workspace);

        return new ProcessRuntimeUnitSpec(
            name: $runtimeUnit,
            configPath: $this->launchdPlistRenderer->plistPath($runtimeUnit, $node),
            configHash: hash('sha256', $content),
            configHashLabel: '',
            restartPolicy: '',
            environmentLines: [],
            label: $this->launchdPlistRenderer->label($runtimeUnit),
        );
    }

    private function dockerSwarmServiceName(Process $process): string
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];

        return $this->optionalConfigString($config, 'service_name') ?? $process->name;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalConfigString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_scalar($item)) {
                continue;
            }

            $map[$key] = (string) $item;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function environmentLines(string $content): array
    {
        return array_values(array_filter(
            explode("\n", $content),
            static fn (string $line): bool => str_starts_with($line, 'Environment='),
        ));
    }
}
