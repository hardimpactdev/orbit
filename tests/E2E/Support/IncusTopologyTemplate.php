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
        $instances = [];

        foreach (self::rolesFor($kind) as $role) {
            $template = self::templateName($kind, $role);
            $clone = self::cloneName($runId, $role);

            $result = $timer->measure("copy.{$role}", fn () => $host->copyInstance($template, $clone));

            if (! $result->successful()) {
                throw new \RuntimeException("Could not copy {$template} to {$clone}: {$result->errorOutput()}");
            }

            $result = $timer->measure("limits.{$role}", fn () => $host->setInstanceLimits(
                $clone,
                $host->config->topologyCpus,
                $host->config->topologyMemory,
            ));

            if (! $result->successful()) {
                throw new \RuntimeException("Could not apply topology limits to {$clone}: {$result->errorOutput()}");
            }

            $result = $timer->measure("start.{$role}", fn () => $host->startInstance($clone));

            if (! $result->successful()) {
                throw new \RuntimeException("Could not start {$clone}: {$result->errorOutput()}");
            }

            $instance = new IncusInstance($host, $clone);
            $timer->measure("agent-ready.{$role}", fn () => $instance->waitForAgent());

            $instances[$role] = $instance;
        }

        return $instances;
    }
}
