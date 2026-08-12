<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\NodeRoleAssignment;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\DnsmasqRuntimeInspector;
use App\Services\Vpn\VpnDnsSwarmManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use Throwable;

final readonly class DnsRuntimeProbe
{
    private const int RUNTIME_READINESS_ATTEMPTS = 60;

    private const int RUNTIME_READINESS_DELAY_MICROSECONDS = 500_000;

    public function __construct(
        private DnsmasqBaseConfigBuilder $baseConfigBuilder,
        private string $rootPath,
        private VpnDnsSwarmManager $swarmManager = new VpnDnsSwarmManager,
        private ?DnsmasqReconciler $dnsmasqReconciler = null,
        private DnsmasqRuntimeInspector $runtimeInspector = new DnsmasqRuntimeInspector,
    ) {}

    public function family(): string
    {
        return 'tool';
    }

    /**
     * @return list<DriftEntry>
     */
    public function probe(): array
    {
        $drift = [];
        $containerId = $this->runtimeInspector->containerId();

        if ($containerId === null) {
            $drift[] = new DriftEntry(
                family: $this->family(),
                key: 'tool.dns_container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-dns container is not present.',
            );

            return $drift;
        }

        if (! $this->portListening($containerId)) {
            $drift[] = new DriftEntry(
                family: $this->family(),
                key: 'tool.dns_port_not_listening',
                kind: DriftKind::Divergent,
                summary: 'orbit-dns is not listening on port 53 inside the wg-easy network namespace.',
            );
        }

        $baseConfigComponents = [];

        if ($this->configDrifted()) {
            $baseConfigComponents[] = 'base_config';
        }

        if (! $this->projectionDirectoryIsMounted($containerId)) {
            $baseConfigComponents[] = 'projection_mount';
        }

        if ($baseConfigComponents !== []) {
            $drift[] = new DriftEntry(
                family: $this->family(),
                key: 'tool.dns_base_config_mismatch',
                kind: DriftKind::Divergent,
                summary: 'orbit-dns base configuration or projection mount differs from tool-owned intent.',
                detail: [
                    'path' => $this->confPath(),
                    'components' => $baseConfigComponents,
                    'projection_source' => $this->projectionPath(),
                    'projection_destination' => DnsmasqRuntimeInspector::ProjectionMount,
                    'projection_read_only' => true,
                ],
            );
        }

        $clientDnsDrift = $this->clientDnsDrift();

        if ($clientDnsDrift instanceof DriftEntry) {
            $drift[] = $clientDnsDrift;
        }

        $forwardingDrift = $this->vpnDnsForwardingDrift();

        if ($forwardingDrift instanceof DriftEntry) {
            $drift[] = $forwardingDrift;
        }

        return $drift;
    }

    /**
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            'tool.dns_container_missing',
            'tool.dns_port_not_listening',
            'tool.dns_base_config_mismatch',
            'tool.dns_client_dns_drift',
            'tool.dns_forwarding_missing',
        ]);
    }

    public function isRestorable(string $driftKey): bool
    {
        return array_key_exists($driftKey, self::restoreSupport());
    }

    public function isAdoptable(string $driftKey): bool
    {
        return false;
    }

    public function restore(string $driftKey): bool
    {
        if (! $this->isRestorable($driftKey)) {
            return false;
        }

        return match ($driftKey) {
            'tool.dns_container_missing' => $this->restoreContainer(),
            'tool.dns_port_not_listening' => $this->restartContainer(),
            'tool.dns_base_config_mismatch' => $this->restoreConfig(),
            'tool.dns_client_dns_drift' => $this->restoreClientDns(),
            'tool.dns_forwarding_missing' => $this->restoreDnsForwarding(),
            default => false,
        };
    }

    public function adopt(string $driftKey): bool
    {
        return false;
    }

    private function portListening(string $containerId): bool
    {
        $result = Process::timeout(15)->run(sprintf(
            'docker exec %s sh -c "netstat -lnu 2>/dev/null | grep :53 || ss -lnu 2>/dev/null | grep :53"',
            escapeshellarg($containerId),
        ));

        return $result->successful() && trim($result->output()) !== '';
    }

    private function configDrifted(): bool
    {
        $confPath = $this->confPath();

        if (! File::exists($confPath)) {
            return true;
        }

        $expected = $this->baseConfigBuilder->build();

        return File::get($confPath) !== $expected;
    }

    private function clientDnsDrift(): ?DriftEntry
    {
        $state = $this->wgEasyDnsState();

        if ($state === null) {
            return null;
        }

        $expectedDns = $this->expectedVpnDnsIp();
        $expectedDnsList = [$expectedDns];
        $defaultDns = $this->decodeDnsList($state['default_dns']);
        $driftedClients = [];

        foreach ($state['clients'] as $client) {
            $clientDns = $this->decodeDnsList($client['dns']);

            if ($clientDns === $expectedDnsList) {
                continue;
            }

            $driftedClients[] = [
                'name' => $client['name'],
                'ipv4_address' => $client['ipv4_address'],
                'dns' => $clientDns ?? [],
            ];
        }

        if ($defaultDns === $expectedDnsList && $driftedClients === []) {
            return null;
        }

        return new DriftEntry(
            family: $this->family(),
            key: 'tool.dns_client_dns_drift',
            kind: DriftKind::Divergent,
            summary: 'wg-easy client DNS is not pinned to the VPN DNS endpoint.',
            detail: [
                'path' => $this->wgEasyDatabasePath(),
                'expected_dns' => $expectedDns,
                'expected_dns_list' => $expectedDnsList,
                'default_dns' => $defaultDns ?? [],
                'clients' => $driftedClients,
            ],
        );
    }

    private function restoreContainer(): bool
    {
        if ($this->legacyMonolithicBaseExists()) {
            return false;
        }

        $this->dnsmasqReconciler()->stageRuntimeLayout();

        if (File::exists($this->swarmStackPath())) {
            $result = Process::timeout(180)->run(sprintf(
                'docker stack deploy -c %s %s',
                escapeshellarg($this->swarmStackPath()),
                escapeshellarg('orbit'),
            ));

            if (! $result->successful() || ! $this->waitForProjectionDirectoryMount()) {
                return false;
            }

            return $this->restoreDnsForwarding();
        }

        $result = Process::timeout(180)->run(sprintf(
            'docker compose -f %s up -d',
            escapeshellarg($this->composePath()),
        ));

        return $result->successful() && $this->waitForProjectionDirectoryMount();
    }

    private function restartContainer(): bool
    {
        if (File::exists($this->swarmStackPath()) && $this->swarmDnsServiceExists()) {
            $result = Process::timeout(30)->run("docker service update --force 'orbit_orbit-dns'");

            return $result->successful() && $this->restoreDnsForwarding();
        }

        $result = Process::timeout(30)->run('docker restart orbit-dns');

        return $result->successful();
    }

    private function restoreConfig(): bool
    {
        if ($this->legacyMonolithicBaseExists()) {
            return false;
        }

        if (! $this->projectionDirectoryIsMounted() && ! $this->restoreContainer()) {
            return false;
        }

        $this->dnsmasqReconciler()->reconcileBase();

        return ! $this->configDrifted() && $this->projectionDirectoryIsMounted();
    }

    private function restoreClientDns(): bool
    {
        $path = $this->wgEasyDatabasePath();

        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        try {
            $database = $this->openWritableWgEasyDatabase($path);
            $expectedDns = $this->expectedDnsJson();

            $database->beginTransaction();
            $database
                ->prepare('update user_configs_table set default_dns = :default_dns')
                ->execute([
                    'default_dns' => $expectedDns,
                ]);
            $database
                ->prepare('update clients_table set dns = :dns')
                ->execute([
                    'dns' => $expectedDns,
                ]);
            $database->commit();

            return true;
        } catch (Throwable) {
            if (isset($database) && $database->inTransaction()) {
                $database->rollBack();
            }

            return false;
        }
    }

    private function vpnDnsForwardingDrift(): ?DriftEntry
    {
        if (! File::exists($this->swarmStackPath())) {
            return null;
        }

        if ($this->swarmManager->dnsForwardingReady()) {
            return null;
        }

        return new DriftEntry(
            family: $this->family(),
            key: 'tool.dns_forwarding_missing',
            kind: DriftKind::Divergent,
            summary: 'VPN DNS forwarding from WireGuard peers to orbit-dns is missing.',
            detail: [
                'vpn_service' => 'orbit_orbit-vpn',
                'dns_service' => 'orbit_orbit-dns',
                'wireguard_interface' => 'wg0',
            ],
        );
    }

    private function restoreDnsForwarding(): bool
    {
        try {
            $this->swarmManager->convergeDnsForwardingIfNeeded();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function confPath(): string
    {
        return $this->rootPath.'/dnsmasq.conf';
    }

    private function projectionPath(): string
    {
        return $this->rootPath.'/dnsmasq.d';
    }

    private function projectionDirectoryIsMounted(?string $containerId = null): bool
    {
        return $this->runtimeInspector->projectionDirectoryIsMounted($this->projectionPath(), $containerId);
    }

    private function waitForProjectionDirectoryMount(): bool
    {
        for ($attempt = 1; $attempt <= self::RUNTIME_READINESS_ATTEMPTS; $attempt++) {
            if ($this->projectionDirectoryIsMounted()) {
                return true;
            }

            if ($attempt < self::RUNTIME_READINESS_ATTEMPTS) {
                usleep(self::RUNTIME_READINESS_DELAY_MICROSECONDS);
            }
        }

        return false;
    }

    private function legacyMonolithicBaseExists(): bool
    {
        return File::exists($this->confPath()) && str_contains(File::get($this->confPath()), 'address=/');
    }

    private function composePath(): string
    {
        return $this->rootPath.'/docker-compose.yaml';
    }

    private function swarmStackPath(): string
    {
        return $this->rootPath.'/swarm/orbit-vpn-dns-stack.yml';
    }

    private function swarmDnsServiceExists(): bool
    {
        $result = Process::timeout(15)->run("docker service inspect 'orbit_orbit-dns'");

        return $result->successful();
    }

    private function dnsmasqReconciler(): DnsmasqReconciler
    {
        return $this->dnsmasqReconciler ?? app(DnsmasqReconciler::class);
    }

    private function wgEasyDatabasePath(): string
    {
        return $this->rootPath.'/wg-easy/wg-easy.db';
    }

    /**
     * @return array{
     *     default_dns: string,
     *     clients: list<array{name: string, ipv4_address: string, dns: string}>
     * }|null
     */
    private function wgEasyDnsState(): ?array
    {
        $path = $this->wgEasyDatabasePath();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $database = $this->openReadonlyWgEasyDatabase($path);
            $defaultDnsStatement = $database->query('select default_dns from user_configs_table limit 1');

            if ($defaultDnsStatement === false) {
                return null;
            }

            $defaultDns = $defaultDnsStatement->fetchColumn();

            if (! is_string($defaultDns)) {
                return null;
            }

            $statement = $database->query(
                'select name, ipv4_address, dns from clients_table where enabled = 1 order by name',
            );

            if ($statement === false) {
                return null;
            }

            $clients = [];

            foreach ($statement->fetchAll() as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = $row['name'] ?? null;
                $ipv4Address = $row['ipv4_address'] ?? null;
                $dns = $row['dns'] ?? null;

                if (! is_string($name) || ! is_string($ipv4Address) || ! is_string($dns)) {
                    continue;
                }

                $clients[] = [
                    'name' => $name,
                    'ipv4_address' => $ipv4Address,
                    'dns' => $dns,
                ];
            }

            return [
                'default_dns' => $defaultDns,
                'clients' => $clients,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function openReadonlyWgEasyDatabase(string $path): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('PDO::SQLITE_OPEN_READONLY')) {
            $options[(int) constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = (int) constant('PDO::SQLITE_OPEN_READONLY');
        }

        return new PDO("sqlite:{$path}", null, null, $options);
    }

    private function openWritableWgEasyDatabase(string $path): PDO
    {
        return new PDO("sqlite:{$path}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function decodeDnsList(string $value): ?array
    {
        $decoded = json_decode($value, associative: true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $addresses = [];

        foreach ($decoded as $address) {
            if (! is_string($address)) {
                return null;
            }

            $addresses[] = $address;
        }

        return $addresses;
    }

    private function expectedDnsJson(): string
    {
        $encoded = json_encode([$this->expectedVpnDnsIp()]);

        return is_string($encoded) ? $encoded : '["10.6.0.1"]';
    }

    private function expectedVpnDnsIp(): string
    {
        $assignment = NodeRoleAssignment::query()
            ->where('role', NodeRoleName::Vpn->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->whereHas('node.roleAssignments', fn ($query) => $query
                ->where('role', NodeRoleName::Gateway->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->orderBy('id')
            ->first();

        if (! $assignment instanceof NodeRoleAssignment) {
            return '10.6.0.1';
        }

        try {
            return VpnRoleSettings::fromArray($assignment->settings ?? [])->dnsIp;
        } catch (Throwable) {
            return '10.6.0.1';
        }
    }
}
