<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\NodeHostPaths;
use InvalidArgumentException;
use LogicException;

final readonly class ProcessExpectedRuntimeUnits
{
    public function __construct(
        private ProcessRuntimeContextResolver $contextResolver,
        private ProcessRuntimeUnitSpecFactory $specFactory,
    ) {}

    /**
     * @return list<string>
     */
    public function names(Process $process): array
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return array_column($this->specifications($process), 'name');
        }

        $resolved = $this->resolve($process);

        if ($resolved === null) {
            return [];
        }

        [$runtime, $node, $app] = $resolved;
        $names = [];

        foreach ($this->contexts($process) as $workspace) {
            $names[] = $this->specFactory->name($runtime, $app, $process, $workspace);
        }

        return $names;
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>, label?: string}>
     */
    public function specifications(Process $process): array
    {
        $resolved = $this->resolve($process);

        if ($resolved === null) {
            return [];
        }

        [$runtime, $node, $app] = $resolved;

        if (! $process->owner instanceof Node && $runtime === ProcessRuntime::Launchd) {
            $this->assertLaunchdPlacement($process, $node);
        }

        $specifications = [];

        foreach ($this->contexts($process) as $workspace) {
            $specifications[] = $this->specFactory
                ->make($runtime, $node, $app, $process, $workspace)
                ->toArray();
        }

        return $specifications;
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>
     */
    public function unlabeledSpecifications(Process $process): array
    {
        return array_map(
            static fn (array $specification): array => [
                'name' => $specification['name'],
                'config_path' => $specification['config_path'],
                'config_hash' => $specification['config_hash'],
                'config_hash_label' => $specification['config_hash_label'],
                'restart_policy' => $specification['restart_policy'],
                'environment_lines' => $specification['environment_lines'],
            ],
            $this->specifications($process),
        );
    }

    /**
     * @return list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>, label: string}>
     */
    public function launchdSpecifications(Process $process): array
    {
        return array_map(
            static function (array $specification): array {
                $label = $specification['label'] ?? null;

                if (! is_string($label) || $label === '') {
                    throw new LogicException('Expected launchd runtime unit label is missing.');
                }

                return [
                    'name' => $specification['name'],
                    'config_path' => $specification['config_path'],
                    'config_hash' => $specification['config_hash'],
                    'config_hash_label' => $specification['config_hash_label'],
                    'restart_policy' => $specification['restart_policy'],
                    'environment_lines' => $specification['environment_lines'],
                    'label' => $label,
                ];
            },
            $this->specifications($process),
        );
    }

    public function launchdPath(string $runtimeUnit, Node $node): string
    {
        return $this->specFactory->launchdPath($runtimeUnit, $node);
    }

    /**
     * @return array{ProcessRuntime, Node, App}|null
     */
    private function resolve(Process $process): ?array
    {
        $process->loadMissing('owner');
        $runtime = $this->contextResolver->runtime($process);

        if ($process->owner instanceof Node) {
            return [$runtime, $process->owner, ProcessRuntimeApp::forNode($process->owner)];
        }

        $this->contextResolver->loadRuntimeApp($process, withWorkspaces: true);
        $app = $process->app;
        $node = $this->contextResolver->executionNode($process);

        if (! $app instanceof App || ! $node instanceof Node) {
            return null;
        }

        $runtime = match ($runtime) {
            ProcessRuntime::Docker => ProcessRuntime::Docker,
            ProcessRuntime::Launchd => ProcessRuntime::Launchd,
            default => ProcessRuntime::Systemd,
        };

        return [$runtime, $node, $app];
    }

    private function assertLaunchdPlacement(Process $process, Node $node): void
    {
        if (! NodeHostPaths::isMacosPlatform($node->platform)) {
            throw new InvalidArgumentException(
                "Launchd runtime for process '{$process->name}' requires a macOS execution node; "
                ."placement resolved to '{$node->name}' ({$node->platform}).",
            );
        }
    }

    /**
     * @return list<\App\Models\Workspace|null>
     */
    private function contexts(Process $process): array
    {
        $process->loadMissing('owner');

        return (
            $process->owner instanceof Node
                ? [null]
                : $this->contextResolver->contexts($process)
        );
    }
}
