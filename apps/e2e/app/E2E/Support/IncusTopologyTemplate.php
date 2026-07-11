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
            E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket => [
                'operator',
                'gateway',
                'dev',
                'prod',
                'agent',
            ],
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
                static fn (array $candidate): string => (
                    'incus info '
                    .escapeshellarg($candidate['template'])
                    .' >/dev/null 2>&1 && '
                    .self::snapshotExistsCommand($candidate['template'], $candidate['snapshot'])
                ),
                self::snapshotCandidates($kind, $role),
            );

            $checks[] = '('.implode(' || ', $snapshotChecks).')';
        }

        return $host->run(implode("\n", $checks), timeoutSeconds: 30)->successful();
    }

    /**
     * @return array<string, IncusInstance>
     */
    public static function clone(
        IncusHost $host,
        E2ETopologyKind $kind,
        string $runId,
        ?E2EPhaseTimer $timer = null,
        bool $stateful = false,
        bool $sourceMounted = false,
        ?IncusWorkerNetwork $network = null,
        ?string $sourcePath = null,
    ): array {
        if ($sourceMounted && (! is_string($sourcePath) || trim($sourcePath) === '')) {
            throw new \InvalidArgumentException(
                'A source-mounted Incus topology requires an explicit host source path.',
            );
        }

        $timer ??= new E2EPhaseTimer;
        $roles = self::rolesFor($kind);

        $script = self::buildBatchScript(
            $host,
            $kind,
            $runId,
            $roles,
            stateful: $stateful,
            sourceMounted: $sourceMounted,
            network: $network,
            sourcePath: $sourcePath,
        );

        $result = $timer->measure('batch.copy-start', fn () => $host->runWithoutMultiplexing($script));

        if (! $result->successful()) {
            self::deleteClonesIfPresent($host, $runId, $roles);

            throw new \RuntimeException(
                "Topology batch failed for {$kind->value}: {$result->errorOutput()}",
            );
        }

        try {
            $timer->measure('clone-ready', fn () => self::awaitClonesReady($host, $runId, $roles, $timer));
        } catch (\Throwable $exception) {
            self::deleteClonesIfPresent($host, $runId, $roles);

            throw $exception;
        }

        $instances = [];
        foreach ($roles as $role) {
            $instances[$role] = new IncusInstance(
                $host,
                self::cloneName($runId, $role),
                commandTransport: true,
                sourceMountedCheckout: $sourceMounted,
                hostSourcePath: $sourcePath,
            );
        }

        return $instances;
    }

    /**
     * @param  list<string>  $roles
     */
    private static function deleteClonesIfPresent(IncusHost $host, string $runId, array $roles): void
    {
        try {
            $host->deleteInstancesIfPresent(array_map(
                static fn (string $role): string => self::cloneName($runId, $role),
                $roles,
            ));
        } catch (\Throwable) {
            // Keep the original acquisition failure visible.
        }
    }

    /**
     * Wait for every clone's Incus agent and refresh its network identity in
     * one parallel host invocation instead of serial per-role round trips.
     *
     * @param  list<string>  $roles
     */
    private static function awaitClonesReady(IncusHost $host, string $runId, array $roles, E2EPhaseTimer $timer): void
    {
        $tasks = [];

        foreach ($roles as $role) {
            $clone = escapeshellarg(self::cloneName($runId, $role));

            $tasks[$role] = implode("\n", [
                'deadline=$((SECONDS + '.$host->config->timeoutSeconds.'))',
                "until incus exec {$clone} -- true >/dev/null 2>&1; do",
                '    if [ "$SECONDS" -ge "$deadline" ]; then echo "Incus agent never became ready on '
                    .self::cloneName($runId, $role)
                    .'." >&2; exit 1; fi',
                '    sleep 1',
                'done',
                "incus exec {$clone} -- sh -lc ".escapeshellarg(IncusInstance::networkIdentityRefreshCommand()),
            ]);
        }

        IncusParallelHostTasks::run(
            $host,
            $tasks,
            $timer,
            'clone-ready',
            timeoutSeconds: $host->config->timeoutSeconds + 120,
            failureMessage: 'Prepared topology clones never became ready',
        );
    }

    /**
     * @param  list<string>  $roles
     */
    public static function buildBatchScript(
        IncusHost $host,
        E2ETopologyKind $kind,
        string $runId,
        array $roles,
        ?bool $stateful = null,
        bool $sourceMounted = false,
        ?IncusWorkerNetwork $network = null,
        ?string $sourcePath = null,
    ): string {
        if ($sourceMounted && (! is_string($sourcePath) || trim($sourcePath) === '')) {
            throw new \InvalidArgumentException(
                'A source-mounted Incus topology requires an explicit host source path.',
            );
        }

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
        $networkLines = [];
        $rootSizeLines = [];
        $statefulLines = [];
        $sourceMountLines = [];
        $startLines = [];
        $waitStartLines = [];
        $stateful ??= getenv('ORBIT_E2E_TOPOLOGY_RESET') === 'stateful-restore';
        $sourcePath = $sourceMounted ? $sourcePath : null;

        $sources = self::resolveSnapshotSources($host, $kind, $roles);

        $index = 0;
        foreach ($roles as $role) {
            $index++;
            $source = $sources[$role];
            $template = escapeshellarg("{$source['template']}/{$source['snapshot']}");
            $clone = escapeshellarg(self::cloneName($runId, $role));
            $macAddress = escapeshellarg(self::cloneMacAddress($runId, $role));

            $copyLines[] = "incus copy {$template} {$clone}{$storagePool} & PID_COPY_{$index}=\$!";
            $waitCopyLines[] = "wait \$PID_COPY_{$index}";
            $limitLines[] = "incus config set {$clone} limits.cpu={$cpus} limits.memory={$memory}";
            $identityLines[] = "incus config device override {$clone} eth0 hwaddr={$macAddress}";
            if ($network !== null) {
                $networkLines[] = $network->attachCommand(self::cloneName($runId, $role));
            }
            $rootSizeLines[] = "incus config device set {$clone} root size={$rootSize} || incus config device override {$clone} root size={$rootSize}";

            if ($stateful) {
                $statefulLines[] = "incus config device set {$clone} root size.state={$stateSize} || incus config device override {$clone} root size.state={$stateSize}";
                $statefulLines[] = "incus config set {$clone} migration.stateful=true";
            }

            if ($sourceMounted) {
                $sourceMountLines = [
                    ...$sourceMountLines,
                    ...self::sourceMountCommands($sourcePath, $clone),
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
            ...$networkLines,
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
    private static function sourceMountCommands(string $sourcePath, string $clone): array
    {
        $source = escapeshellarg($sourcePath);

        return [
            "if incus config device get {$clone} orbit-source path >/dev/null 2>&1; then",
            "  incus config device set {$clone} orbit-source source={$source}",
            "  incus config device set {$clone} orbit-source path='/home/orbit/orbit'",
            "  incus config device set {$clone} orbit-source shift=true",
            'else',
            "  incus config device add {$clone} orbit-source disk source={$source} path='/home/orbit/orbit' shift=true",
            'fi',
        ];
    }

    private static function cloneMacAddress(string $runId, string $role): string
    {
        $hash = substr(sha1("{$runId}:{$role}"), 0, 6);

        return '00:16:3e:'.implode(':', str_split($hash, 2));
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
     * Resolve the template/snapshot source for every role in one host call.
     *
     * Branch-namespaced candidates fall back to the matching base candidate
     * per role; roles whose markers are missing fall back to their first
     * candidate, matching the previous per-role resolution behavior.
     *
     * @param  list<string>  $roles
     * @return array<string, array{template: string, snapshot: string}>
     */
    private static function resolveSnapshotSources(IncusHost $host, E2ETopologyKind $kind, array $roles): array
    {
        $fallbacks = [];
        $lines = [];

        foreach ($roles as $role) {
            $candidates = self::snapshotCandidates($kind, $role);
            $fallbacks[$role] = $candidates[0];

            foreach ($candidates as $index => $candidate) {
                $lines[] = sprintf(
                    '%s incus info %s >/dev/null 2>&1 && %s; then',
                    $index === 0 ? 'if' : 'elif',
                    escapeshellarg($candidate['template']),
                    self::snapshotExistsCommand($candidate['template'], $candidate['snapshot']),
                );
                $lines[] = '    echo '
                .escapeshellarg(
                    "__orbit_snapshot_source {$role} {$candidate['template']} {$candidate['snapshot']}",
                );
            }

            $lines[] = 'else';
            $lines[] = '    echo '
            .escapeshellarg(
                "__orbit_snapshot_source {$role} {$fallbacks[$role]['template']} {$fallbacks[$role]['snapshot']}",
            );
            $lines[] = 'fi';
        }

        $result = $host->run(implode("\n", $lines), timeoutSeconds: 60);
        $resolved = [];

        if (
            $result->successful()
            && preg_match_all(
                '/__orbit_snapshot_source\s+(\S+)\s+(\S+)\s+(\S+)/',
                $result->output(),
                $matches,
                PREG_SET_ORDER,
            ) !== false
        ) {
            foreach ($matches as $match) {
                $resolved[$match[1]] = [
                    'template' => $match[2],
                    'snapshot' => $match[3],
                ];
            }
        }

        foreach ($roles as $role) {
            $resolved[$role] ??= $fallbacks[$role];
        }

        return $resolved;
    }

    private static function snapshotExistsCommand(string $template, string $snapshot): string
    {
        return sprintf(
            'incus query %s >/dev/null 2>&1',
            escapeshellarg("/1.0/instances/{$template}/snapshots/{$snapshot}"),
        );
    }
}
