<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class IncusTopologyTemplate
{
    /**
     * @return list<string>
     */
    public static function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Control => ['control'],
            E2ETopologyKind::ControlGateway => ['control', 'gateway'],
            E2ETopologyKind::ControlGatewayDev => ['control', 'gateway', 'dev'],
            E2ETopologyKind::ControlGatewayDevProd => ['control', 'gateway', 'dev', 'prod'],
        };
    }

    public static function templateName(E2ETopologyKind $kind, string $role): string
    {
        return "orbit-template-{$kind->value}-{$role}";
    }

    public static function cloneName(string $runId, string $role): string
    {
        return "orbit-e2e-{$runId}-{$role}";
    }

    public static function availableOn(IncusHost $host, E2ETopologyKind $kind): bool
    {
        foreach (self::rolesFor($kind) as $role) {
            if (! $host->instanceExists(self::templateName($kind, $role))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, IncusInstance>
     */
    public static function clone(IncusHost $host, E2ETopologyKind $kind, string $runId, ?E2EPhaseTimer $timer = null): array
    {
        $timer ??= new E2EPhaseTimer;
        $roles = self::rolesFor($kind);

        $script = self::buildBatchScript($host, $kind, $runId, $roles);

        $result = $timer->measure('batch.copy-start', fn () => $host->run($script));

        if (! $result->successful()) {
            throw new \RuntimeException(
                "Topology batch failed for {$kind->value}: {$result->errorOutput()}"
            );
        }

        $instances = [];
        foreach ($roles as $role) {
            $clone = self::cloneName($runId, $role);
            $instance = new IncusInstance($host, $clone);
            $timer->measure("agent-ready.{$role}", fn () => $instance->waitForAgent());
            $instances[$role] = $instance;
        }

        return $instances;
    }

    /**
     * @param  list<string>  $roles
     */
    public static function buildBatchScript(IncusHost $host, E2ETopologyKind $kind, string $runId, array $roles): string
    {
        $cpus = escapeshellarg($host->config->topologyCpus);
        $memory = escapeshellarg($host->config->topologyMemory);

        $copyLines = [];
        $waitCopyLines = [];
        $limitLines = [];
        $startLines = [];
        $waitStartLines = [];

        $index = 0;
        foreach ($roles as $role) {
            $index++;
            $template = escapeshellarg(self::templateName($kind, $role));
            $clone = escapeshellarg(self::cloneName($runId, $role));

            $copyLines[] = "incus copy {$template} {$clone} & PID_COPY_{$index}=\$!";
            $waitCopyLines[] = "wait \$PID_COPY_{$index}";
            $limitLines[] = "incus config set {$clone} limits.cpu={$cpus} limits.memory={$memory}";
            $startLines[] = "incus start {$clone} & PID_START_{$index}=\$!";
            $waitStartLines[] = "wait \$PID_START_{$index}";
        }

        return implode("\n", [
            ...$copyLines,
            ...$waitCopyLines,
            ...$limitLines,
            ...$startLines,
            ...$waitStartLines,
        ]);
    }
}
