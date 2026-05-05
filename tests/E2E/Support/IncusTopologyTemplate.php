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
        $checks = [];

        foreach (self::rolesFor($kind) as $role) {
            $template = self::templateName($kind, $role);

            $checks[] = 'incus info '.escapeshellarg($template).' >/dev/null 2>&1';
            $checks[] = sprintf(
                'incus info %s --show-log=false 2>/dev/null | grep -q %s',
                escapeshellarg($template),
                escapeshellarg('clean'),
            );
        }

        return $host->run(implode("\n", $checks), timeoutSeconds: 30)->successful();
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
            $instance = new IncusInstance($host, $clone, commandTransport: true);
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
        $stateSize = escapeshellarg($host->config->topologyStateSize);
        $storagePool = $host->storagePoolArgument();
        $storagePool = $storagePool !== '' ? " {$storagePool}" : '';

        $copyLines = [];
        $waitCopyLines = [];
        $limitLines = [];
        $statefulLines = [];
        $startLines = [];
        $waitStartLines = [];
        $statefulReset = getenv('ORBIT_E2E_TOPOLOGY_RESET') === 'stateful-restore';

        $index = 0;
        foreach ($roles as $role) {
            $index++;
            $template = escapeshellarg(self::templateName($kind, $role).'/clean');
            $clone = escapeshellarg(self::cloneName($runId, $role));

            $copyLines[] = "incus copy {$template} {$clone}{$storagePool} & PID_COPY_{$index}=\$!";
            $waitCopyLines[] = "wait \$PID_COPY_{$index}";
            $limitLines[] = "incus config set {$clone} limits.cpu={$cpus} limits.memory={$memory}";

            if ($statefulReset) {
                $statefulLines[] = "incus config device set {$clone} root size.state={$stateSize} || incus config device override {$clone} root size.state={$stateSize}";
                $statefulLines[] = "incus config set {$clone} migration.stateful=true";
            }

            $startLines[] = "incus start {$clone} & PID_START_{$index}=\$!";
            $waitStartLines[] = "wait \$PID_START_{$index}";
        }

        return implode("\n", [
            ...$copyLines,
            ...$waitCopyLines,
            ...$limitLines,
            ...$statefulLines,
            ...$startLines,
            ...$waitStartLines,
        ]);
    }
}
