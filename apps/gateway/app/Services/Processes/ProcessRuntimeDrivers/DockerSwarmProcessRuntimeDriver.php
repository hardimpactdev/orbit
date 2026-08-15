<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\RemoteDockerSwarmService;
use InvalidArgumentException;
use Throwable;

final readonly class DockerSwarmProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private RemoteDockerSwarmService $services,
    ) {}

    public function runtimeUnitName(?App $app, Process $process, ?Workspace $workspace = null): string
    {
        $config = $this->runtimeConfig($process);
        $serviceName = $this->optionalString($config, 'service_name') ?? $process->name;

        return $this->assertServiceName($serviceName);
    }

    public function apply(
        Node $node,
        ?App $app,
        Process $process,
        ?Workspace $workspace = null,
    ): bool {
        try {
            $runtimeUnit = $this->runtimeUnitName($app, $process, $workspace);
            $replacementRuntimeUnit = $this->replacementRuntimeUnit($process);

            if (
                $replacementRuntimeUnit !== null
                && $replacementRuntimeUnit !== $runtimeUnit
                && ! $this->services->remove($node, $replacementRuntimeUnit)
            ) {
                return false;
            }

            if (! $this->services->apply($node, $runtimeUnit, $this->serviceSpec($process, $runtimeUnit))) {
                return false;
            }

            $this->clearReplacementRuntimeUnit($process);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->services->remove($node, $this->assertServiceName($runtimeUnit));
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        return 'docker service rm '.escapeshellarg($this->assertServiceName($runtimeUnit)).' 2>/dev/null || true';
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->services->start($node, $this->assertServiceName($runtimeUnit));
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->services->stop($node, $this->assertServiceName($runtimeUnit));
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->services->restart($node, $this->assertServiceName($runtimeUnit));
    }

    public function isRunning(Node $node, string $runtimeUnit): bool
    {
        return $this->services->isActive($node, $this->assertServiceName($runtimeUnit));
    }

    public function logScript(
        ?App $app,
        Process $process,
        ?Workspace $workspace,
        string $runtimeUnit,
        int $lines,
        bool $follow,
    ): string {
        return collect([
            'docker service logs',
            "--tail {$lines}",
            $follow ? '--follow' : null,
            escapeshellarg($this->assertServiceName($runtimeUnit)),
            '2>&1',
        ])
            ->filter()
            ->implode(' ');
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceSpec(Process $process, string $runtimeUnit): array
    {
        $config = $this->runtimeConfig($process);
        $specHash =
            $this->optionalString($config, 'spec_hash') ?? $this->optionalString(
                $this->stringMap($config['labels'] ?? []),
                'orbit.process.spec_hash',
            ) ?? '';

        $usesImageEntrypoint = $this->usesImageEntrypoint($config);

        return [
            'image' => $this->requiredString($config, 'image', $process),
            'command' => $process->command,
            'command_mode' => $usesImageEntrypoint ? 'image_entrypoint' : 'shell',
            'restart_condition' => $this->restartCondition($process),
            'labels' => $this->stringMap($config['labels'] ?? []),
            'ports' => $this->ports($config['ports'] ?? []),
            'mounts' => [
                ...$this->volumes($config['volumes'] ?? []),
                ...$this->bindMounts($config['bind_mounts'] ?? []),
            ],
            'environment' => $this->stringMap($config['environment'] ?? []),
            'update_order' => $this->updateOrder($config),
            'update_parallelism' => $this->updateParallelism($config),
            'expected_hash' => $specHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function usesImageEntrypoint(array $config): bool
    {
        return match ($this->optionalString($config, 'command_mode') ?? 'shell') {
            'shell' => false,
            'image_entrypoint' => true,
            default => throw new InvalidArgumentException(
                'Docker Swarm command_mode must be shell or image_entrypoint.',
            ),
        };
    }

    private function restartCondition(Process $process): string
    {
        return match ($process->restart_policy) {
            ProcessRestartPolicy::Never => 'none',
            ProcessRestartPolicy::OnFailure => 'on-failure',
            ProcessRestartPolicy::Always => 'any',
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function updateOrder(array $config): string
    {
        $updateStrategy = is_array($config['update_strategy'] ?? null) ? $config['update_strategy'] : [];

        return $this->optionalString($updateStrategy, 'order') ?? 'stop-first';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function updateParallelism(array $config): string
    {
        $updateStrategy = is_array($config['update_strategy'] ?? null) ? $config['update_strategy'] : [];

        return (string) max(1, (int) ($updateStrategy['parallelism'] ?? 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeConfig(Process $process): array
    {
        return is_array($process->runtime_config) ? $process->runtime_config : [];
    }

    private function replacementRuntimeUnit(Process $process): ?string
    {
        $runtimeUnit = $this->runtimeConfig($process)['replaces_runtime_unit'] ?? null;

        return is_string($runtimeUnit) && $runtimeUnit !== '' ? $runtimeUnit : null;
    }

    private function clearReplacementRuntimeUnit(Process $process): void
    {
        $runtimeConfig = $this->runtimeConfig($process);

        if (! array_key_exists('replaces_runtime_unit', $runtimeConfig)) {
            return;
        }

        unset($runtimeConfig['replaces_runtime_unit']);
        $process->forceFill(['runtime_config' => $runtimeConfig])->save();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requiredString(array $config, string $key, Process $process): string
    {
        $value = $this->optionalString($config, $key);

        if ($value !== null) {
            return $value;
        }

        throw new InvalidArgumentException(
            "Process '{$process->name}' is missing runtime_config.{$key}; cannot render Docker Swarm service.",
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalString(array $config, string $key): ?string
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

        ksort($map);

        return $map;
    }

    /**
     * @return list<string>
     */
    private function ports(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $port): ?string {
            if (! is_array($port)) {
                return null;
            }

            $published = (int) ($port['published'] ?? 0);
            $target = (int) ($port['target'] ?? 0);
            $protocol = is_string($port['protocol'] ?? null) ? trim($port['protocol']) : 'tcp';

            if ($published < 1 || $target < 1) {
                return null;
            }

            return "published={$published},target={$target},protocol=".($protocol !== '' ? $protocol : 'tcp');
        }, $value)));
    }

    /**
     * @return list<string>
     */
    private function bindMounts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $mount): ?string {
            if (! is_array($mount)) {
                return null;
            }

            $source = is_string($mount['source'] ?? null) ? trim($mount['source']) : '';
            $target = is_string($mount['target'] ?? null) ? trim($mount['target']) : '';

            if (
                $source === ''
                || $target === ''
                || ! str_starts_with($source, '/')
                || ! str_starts_with($target, '/')
            ) {
                return null;
            }

            return (
                'type=bind,source='
                .$source
                .',target='
                .$target
                .((bool) ($mount['read_only'] ?? false) ? ',readonly' : '')
            );
        }, $value)));
    }

    /**
     * @return list<string>
     */
    private function volumes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $volume): ?string {
            if (! is_array($volume)) {
                return null;
            }

            $source = is_string($volume['source'] ?? null) ? trim($volume['source']) : null;
            $name = is_string($volume['name'] ?? null) ? trim($volume['name']) : null;
            $target = is_string($volume['target'] ?? null) ? trim($volume['target']) : null;

            if ($source === null && $name === null || $target === null || $target === '') {
                return null;
            }

            return 'type=volume,source='.($source ?? $name).",target={$target}";
        }, $value)));
    }

    private function assertServiceName(string $value): string
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $value)) {
            throw new InvalidArgumentException("Unsafe Docker Swarm service name: {$value}");
        }

        return $value;
    }
}
