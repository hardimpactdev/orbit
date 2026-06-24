<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class IncusWarmTopologyPool
{
    public const string EnabledEnvironmentVariable = 'ORBIT_E2E_INCUS_WARM_SNAPSHOTS';

    public const string SlotsEnvironmentVariable = 'ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS';

    public const string SnapshotName = 'warm-ready';

    public static function enabled(): bool
    {
        $value = getenv(self::EnabledEnvironmentVariable);

        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    public static function backend(E2ETopologyKind $kind): string
    {
        return "incus-warm-{$kind->value}";
    }

    public static function runId(E2ETopologyKind $kind, int $slot): string
    {
        $hash = substr(sha1(E2ETopologyArtifactNamespace::artifactSet().":{$kind->value}:{$slot}"), 0, 10);

        return "warm-{$hash}-s{$slot}";
    }

    public static function instanceName(E2ETopologyKind $kind, int $slot, string $role): string
    {
        return IncusTopologyTemplate::cloneName(self::runId($kind, $slot), $role);
    }

    public static function sshKeyPair(E2ETopologyKind $kind, int $slot): SshKeyPair
    {
        $privateKeyPath = '/tmp/orbit-e2e-topology-'.self::runId($kind, $slot).'/id_ed25519';

        return new SshKeyPair($privateKeyPath, "{$privateKeyPath}.pub");
    }

    /**
     * @return array<string, int>
     */
    public static function availableHostSlots(E2EConfig $config, E2ETopologyKind $kind): array
    {
        $hostSlots = [];

        foreach ($config->incusHostCandidates() as $hostName) {
            $host = new IncusHost($config->forHost($hostName));
            $slots = self::availableSlotsOn($host, $kind);

            if ($slots > 0) {
                $hostSlots[$hostName] = $slots;
            }
        }

        return $hostSlots;
    }

    public static function configuredSlotsForHost(E2EConfig $config, E2ETopologyKind $kind, string $hostName): int
    {
        $requested = self::envInt(self::SlotsEnvironmentVariable, 1);
        $maxSlots = self::maxSlotsForHost($config, $kind, $hostName);

        return max(0, min($requested, $maxSlots));
    }

    public static function maxSlotsForHost(E2EConfig $config, E2ETopologyKind $kind, string $hostName): int
    {
        $requiredVms = count(IncusTopologyTemplate::rolesFor($kind));
        $hostCap = $config->incusMaxVmsForHost($hostName);

        return max(0, intdiv($hostCap, $requiredVms));
    }

    public static function availableSlotsOn(IncusHost $host, E2ETopologyKind $kind): int
    {
        $slots = self::configuredSlotsForHost($host->config, $kind, $host->config->host);
        $available = 0;

        for ($slot = 1; $slot <= $slots; $slot++) {
            if (! self::slotAvailableOn($host, $kind, $slot)) {
                break;
            }

            $available++;
        }

        return $available;
    }

    public static function slotAvailableOn(IncusHost $host, E2ETopologyKind $kind, int $slot): bool
    {
        $checks = [];

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            $name = self::instanceName($kind, $slot, $role);

            $checks[] = sprintf(
                'incus info %1$s >/dev/null 2>&1 && incus query %2$s >/dev/null 2>&1',
                escapeshellarg($name),
                escapeshellarg("/1.0/instances/{$name}/snapshots/".self::SnapshotName),
            );
        }

        return $host->run(implode("\n", $checks), timeoutSeconds: 30)->successful();
    }

    /**
     * @return array<string, IncusInstance>
     */
    public static function instancesFor(IncusHost $host, E2ETopologyKind $kind, int $slot): array
    {
        $instances = [];

        foreach (IncusTopologyTemplate::rolesFor($kind) as $role) {
            $instances[$role] = new IncusInstance(
                $host,
                self::instanceName($kind, $slot, $role),
                commandTransport: true,
            );
        }

        return $instances;
    }

    /**
     * @return list<string>
     */
    public static function instanceNames(E2ETopologyKind $kind, int $slot): array
    {
        return array_map(
            fn (string $role): string => self::instanceName($kind, $slot, $role),
            IncusTopologyTemplate::rolesFor($kind),
        );
    }

    private static function envInt(string $key, int $default): int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }
}
