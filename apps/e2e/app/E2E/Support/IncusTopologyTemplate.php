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
        $roles = match ($kind) {
            E2ETopologyKind::Operator => ['operator'],
            E2ETopologyKind::OperatorGateway => ['operator', 'gateway'],
            E2ETopologyKind::OperatorGatewayAppdev => ['operator', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprod => ['operator', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodIngress => ['operator', 'gateway', 'dev', 'prod', 'ingress'],
            E2ETopologyKind::OperatorGatewayAgent => ['operator', 'gateway', 'agent'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgent => ['operator', 'gateway', 'dev', 'prod', 'agent'],
            E2ETopologyKind::OperatorGatewayAppprodIngress => ['operator', 'gateway', 'prod', 'ingress'],
            E2ETopologyKind::OperatorGatewayAppdevWebsocket => ['operator', 'gateway', 'dev'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket => ['operator', 'gateway', 'dev', 'prod'],
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket => ['operator', 'gateway', 'dev', 'prod', 'agent'],
        };

        if (E2EPreparedTopology::prodHostsIngressRole($kind)) {
            return array_values(array_filter($roles, fn (string $role): bool => $role !== 'ingress'));
        }

        return $roles;
    }

    public static function templateName(E2ETopologyKind $kind, string $role): string
    {
        return E2ETopologyArtifactNamespace::incusTemplateName('orbit-template-'.self::artifactRole($role));
    }

    public static function baseTemplateName(E2ETopologyKind $kind, string $role): string
    {
        return E2ETopologyArtifactNamespace::incusBaseTemplateName('orbit-template-'.self::artifactRole($role));
    }

    public static function snapshotName(E2ETopologyKind $kind): string
    {
        return E2ETopologyArtifactNamespace::incusSnapshotName($kind);
    }

    public static function baseSnapshotName(E2ETopologyKind $kind): string
    {
        return E2ETopologyArtifactNamespace::incusBaseSnapshotName($kind);
    }

    /**
     * @return list<array{template: string, snapshot: string}>
     */
    public static function snapshotCandidates(E2ETopologyKind $kind, string $role): array
    {
        $sourceKind = E2EPreparedTopology::incusSourceKindFor($kind);
        $candidate = [
            'template' => self::templateName($sourceKind, $role),
            'snapshot' => self::snapshotName($sourceKind),
        ];
        $baseCandidate = [
            'template' => self::baseTemplateName($sourceKind, $role),
            'snapshot' => self::baseSnapshotName($sourceKind),
        ];

        if ($candidate === $baseCandidate) {
            return [$candidate];
        }

        return [$candidate, $baseCandidate];
    }

    public static function cloneName(string $runId, string $role): string
    {
        return "orbit-e2e-{$runId}-{$role}";
    }

    public static function availableOn(IncusHost $host, E2ETopologyKind $kind): bool
    {
        $checks = [];

        foreach (self::rolesFor($kind) as $role) {
            $snapshotChecks = array_map(
                static fn (array $candidate): string => 'incus info '.escapeshellarg($candidate['template']).' >/dev/null 2>&1 && '.self::snapshotExistsCommand($candidate['template'], $candidate['snapshot']),
                self::snapshotCandidates($kind, $role),
            );

            $checks[] = '('.implode(' || ', $snapshotChecks).')';
        }

        return $host->run(implode("\n", $checks), timeoutSeconds: 30)->successful();
    }

    /**
     * @return array<string, IncusInstance>
     */
    public static function clone(IncusHost $host, E2ETopologyKind $kind, string $runId, ?E2EPhaseTimer $timer = null, bool $stateful = false, bool $sourceMounted = false, bool $readonlySourceMount = false, ?string $networkName = null, ?string $subnetPrefix = null): array
    {
        $timer ??= new E2EPhaseTimer;
        $roles = self::rolesFor($kind);

        $script = self::buildBatchScript($host, $kind, $runId, $roles, stateful: $stateful, sourceMounted: $sourceMounted, readonlySourceMount: $readonlySourceMount, networkName: $networkName, subnetPrefix: $subnetPrefix);

        $result = $timer->measure('batch.copy-start', fn () => $host->run($script));

        if (! $result->successful()) {
            throw new \RuntimeException(
                "Topology batch failed for {$kind->value}: {$result->errorOutput()}"
            );
        }

        $instances = [];
        foreach ($roles as $role) {
            $clone = self::cloneName($runId, $role);
            $instance = new IncusInstance($host, $clone, commandTransport: true, sourceMountedCheckout: $sourceMounted, readonlySourceMount: $readonlySourceMount);
            $timer->measure("agent-ready.{$role}", fn () => $instance->waitForAgent());
            $timer->measure("network-identity.{$role}", fn () => $instance->refreshNetworkIdentity());
            $instances[$role] = $instance;
        }

        return $instances;
    }

    /**
     * @param  list<string>  $roles
     */
    public static function buildBatchScript(IncusHost $host, E2ETopologyKind $kind, string $runId, array $roles, ?bool $stateful = null, bool $sourceMounted = false, bool $readonlySourceMount = false, ?string $networkName = null, ?string $subnetPrefix = null): string
    {
        $cpus = escapeshellarg($host->config->topologyCpus);
        $memory = escapeshellarg($host->config->topologyMemory);
        $rootSize = escapeshellarg($host->config->topologyRootSize);
        $stateSize = escapeshellarg($host->config->topologyStateSize);
        $storagePool = $host->storagePoolArgument();
        $storagePool = $storagePool !== '' ? " {$storagePool}" : '';

        $copyLines = [];
        $waitCopyLines = [];
        $limitLines = [];
        $identityLines = [];
        $rootSizeLines = [];
        $statefulLines = [];
        $sourceMountLines = [];
        $startLines = [];
        $waitStartLines = [];
        $stateful ??= getenv('ORBIT_E2E_TOPOLOGY_RESET') === 'stateful-restore';
        $sourcePath = $sourceMounted ? $host->sourcePath() : null;

        $index = 0;
        foreach ($roles as $role) {
            $index++;
            $source = self::resolveSnapshotSource($host, $kind, $role);
            $template = escapeshellarg("{$source['template']}/{$source['snapshot']}");
            $clone = escapeshellarg(self::cloneName($runId, $role));
            $macAddress = escapeshellarg(self::cloneMacAddress($runId, $role));

            $copyLines[] = "incus copy {$template} {$clone}{$storagePool} & PID_COPY_{$index}=\$!";
            $waitCopyLines[] = "wait \$PID_COPY_{$index}";
            $limitLines[] = "incus config set {$clone} limits.cpu={$cpus} limits.memory={$memory}";
            $networkAttr = '';
            if ($networkName !== null) {
                $networkAttr .= ' network='.escapeshellarg($networkName);
                if ($subnetPrefix !== null) {
                    $networkAttr .= ' ipv4.address='.escapeshellarg($subnetPrefix.'.'.self::staticIpOctetForRole($role));
                }
            }

            $identityLines[] = "incus config device override {$clone} eth0 hwaddr={$macAddress}{$networkAttr}";
            $rootSizeLines[] = "incus config device set {$clone} root size={$rootSize} || incus config device override {$clone} root size={$rootSize}";

            if ($stateful) {
                $statefulLines[] = "incus config device set {$clone} root size.state={$stateSize} || incus config device override {$clone} root size.state={$stateSize}";
                $statefulLines[] = "incus config set {$clone} migration.stateful=true";
            }

            if ($sourceMounted) {
                $sourceMountLines = [
                    ...$sourceMountLines,
                    ...self::sourceMountCommands($sourcePath, $clone, $readonlySourceMount),
                ];
            }

            $startLines[] = "incus start {$clone} & PID_START_{$index}=\$!";
            $waitStartLines[] = "wait \$PID_START_{$index}";
        }

        return implode("\n", [
            ...$copyLines,
            ...$waitCopyLines,
            ...$limitLines,
            ...$identityLines,
            ...$rootSizeLines,
            ...$statefulLines,
            ...$sourceMountLines,
            ...$startLines,
            ...$waitStartLines,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function sourceMountCommands(string $sourcePath, string $clone, bool $readonly = false): array
    {
        $source = escapeshellarg($sourcePath);

        return [
            "if incus config device get {$clone} orbit-source path >/dev/null 2>&1; then",
            "  incus config device set {$clone} orbit-source source={$source}",
            "  incus config device set {$clone} orbit-source path='/home/orbit/orbit'",
            "  incus config device set {$clone} orbit-source shift=true",
            "  incus config device set {$clone} orbit-source readonly=".($readonly ? 'true' : 'false'),
            'else',
            "  incus config device add {$clone} orbit-source disk source={$source} path='/home/orbit/orbit' shift=true readonly=".($readonly ? 'true' : 'false'),
            'fi',
        ];
    }

    private static function cloneMacAddress(string $runId, string $role): string
    {
        $hash = substr(sha1("{$runId}:{$role}"), 0, 6);

        return '00:16:3e:'.implode(':', str_split($hash, 2));
    }

    private static function staticIpOctetForRole(string $role): int
    {
        return match ($role) {
            'gateway' => 2,
            'operator' => 3,
            'dev' => 4,
            'prod' => 5,
            'agent' => 6,
            'ingress' => 7,
            'websocket' => 8,
            default => throw new \RuntimeException("Unknown role [{$role}] for static IP allocation."),
        };
    }

    private static function artifactRole(string $role): string
    {
        return match ($role) {
            'operator' => 'operator',
            'dev' => 'app-dev',
            'prod' => 'app-prod',
            default => $role,
        };
    }

    /**
     * @return array{template: string, snapshot: string}
     */
    private static function resolveSnapshotSource(IncusHost $host, E2ETopologyKind $kind, string $role): array
    {
        foreach (self::snapshotCandidates($kind, $role) as $candidate) {
            $result = $host->run(
                'incus info '.escapeshellarg($candidate['template']).' >/dev/null 2>&1 && '.self::snapshotExistsCommand($candidate['template'], $candidate['snapshot']),
                timeoutSeconds: 30,
            );

            if ($result->successful()) {
                return $candidate;
            }
        }

        return self::snapshotCandidates($kind, $role)[0];
    }

    private static function snapshotExistsCommand(string $template, string $snapshot): string
    {
        return sprintf(
            'incus query %s >/dev/null 2>&1',
            escapeshellarg("/1.0/instances/{$template}/snapshots/{$snapshot}"),
        );
    }
}
