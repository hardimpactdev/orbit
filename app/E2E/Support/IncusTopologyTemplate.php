<?php

declare(strict_types=1);

namespace App\E2E\Support;

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
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['control', 'gateway', 'dev', 'prod', 'agent'],
        };
    }

    public static function templateName(E2ETopologyKind $kind, string $role): string
    {
        return "orbit-template-{$role}";
    }

    public static function snapshotName(E2ETopologyKind $kind): string
    {
        return "clean-{$kind->value}";
    }

    /**
     * @return list<string>
     */
    public static function snapshotCandidates(E2ETopologyKind $kind): array
    {
        return [
            self::snapshotName($kind),
            ...array_map(
                static fn (string $value): string => "clean-{$value}",
                $kind->deprecatedValues(),
            ),
        ];
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

            $snapshotChecks = array_map(
                static fn (string $snapshot): string => self::snapshotExistsCommand($template, $snapshot),
                self::snapshotCandidates($kind),
            );

            $checks[] = '('.implode(' || ', $snapshotChecks).')';
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
            $timer->measure("network-identity.{$role}", fn () => $instance->refreshNetworkIdentity());
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
        $identityLines = [];
        $statefulLines = [];
        $startLines = [];
        $waitStartLines = [];
        $statefulReset = getenv('ORBIT_E2E_TOPOLOGY_RESET') === 'stateful-restore';

        $index = 0;
        foreach ($roles as $role) {
            $index++;
            $snapshot = self::resolveSnapshotName($host, $kind, $role);
            $template = escapeshellarg(self::templateName($kind, $role)."/{$snapshot}");
            $clone = escapeshellarg(self::cloneName($runId, $role));
            $macAddress = escapeshellarg(self::cloneMacAddress($runId, $role));

            $copyLines[] = "incus copy {$template} {$clone}{$storagePool} & PID_COPY_{$index}=\$!";
            $waitCopyLines[] = "wait \$PID_COPY_{$index}";
            $limitLines[] = "incus config set {$clone} limits.cpu={$cpus} limits.memory={$memory}";
            $identityLines[] = "incus config device override {$clone} eth0 hwaddr={$macAddress}";

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
            ...$identityLines,
            ...$statefulLines,
            ...$startLines,
            ...$waitStartLines,
        ]);
    }

    private static function cloneMacAddress(string $runId, string $role): string
    {
        $hash = substr(sha1("{$runId}:{$role}"), 0, 6);

        return '00:16:3e:'.implode(':', str_split($hash, 2));
    }

    private static function resolveSnapshotName(IncusHost $host, E2ETopologyKind $kind, string $role): string
    {
        $template = self::templateName($kind, $role);

        foreach (self::snapshotCandidates($kind) as $snapshot) {
            $result = $host->run(self::snapshotExistsCommand($template, $snapshot), timeoutSeconds: 30);

            if ($result->successful()) {
                return $snapshot;
            }
        }

        return self::snapshotName($kind);
    }

    private static function snapshotExistsCommand(string $template, string $snapshot): string
    {
        return sprintf(
            'incus query %s >/dev/null 2>&1',
            escapeshellarg("/1.0/instances/{$template}/snapshots/{$snapshot}"),
        );
    }
}
