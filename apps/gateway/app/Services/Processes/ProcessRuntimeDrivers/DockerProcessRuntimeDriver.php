<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use Throwable;

/** @mago-expect lint:too-many-methods */
final readonly class DockerProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private ProcessDockerContainerRenderer $renderer,
        private ProcessDockerRuntimeManager $manager,
    ) {}

    public function runtimeUnitName(?App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->containerName($app, $process, $workspace);
    }

    public function apply(
        Node $node,
        ?App $app,
        Process $process,
        ?Workspace $workspace = null,
    ): bool {
        try {
            $container = $this->renderer->render($app, $process, $workspace);
            $replacementRuntimeUnit = $this->replacementRuntimeUnit($process);

            if (
                $replacementRuntimeUnit !== null
                && $replacementRuntimeUnit !== $container->name()
                && ! $this->manager->remove($node, $replacementRuntimeUnit)
            ) {
                return false;
            }

            $this->manager->apply(
                node: $node,
                container: $container,
                preparePrerequisites: $process->owner instanceof Node,
            );
            $this->clearReplacementRuntimeUnit($process);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->remove($node, $runtimeUnit);
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        return sprintf('docker rm -f %s 2>/dev/null || true', escapeshellarg($runtimeUnit));
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->start($node, $runtimeUnit);
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->stop($node, $runtimeUnit);
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->restart($node, $runtimeUnit);
    }

    public function isRunning(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->isRunning($node, $runtimeUnit);
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
            'docker logs',
            "--tail {$lines}",
            $follow ? '--follow' : null,
            escapeshellarg($runtimeUnit),
            '2>&1',
        ])
            ->filter()
            ->implode(' ');
    }

    private function replacementRuntimeUnit(Process $process): ?string
    {
        $runtimeConfig = is_array($process->runtime_config) ? $process->runtime_config : [];
        $runtimeUnit = $runtimeConfig['replaces_runtime_unit'] ?? null;

        return is_string($runtimeUnit) && $runtimeUnit !== '' ? $runtimeUnit : null;
    }

    private function clearReplacementRuntimeUnit(Process $process): void
    {
        $runtimeConfig = is_array($process->runtime_config) ? $process->runtime_config : [];

        if (! array_key_exists('replaces_runtime_unit', $runtimeConfig)) {
            return;
        }

        unset($runtimeConfig['replaces_runtime_unit']);
        $process->forceFill(['runtime_config' => $runtimeConfig])->save();
    }
}
