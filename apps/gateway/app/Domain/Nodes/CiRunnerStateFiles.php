<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Metrics\MetricsFootprint;

/**
 * The `ci-runner` role's runner state timer (docs/reference/node-provisioning.md#runner-state-for-the-runner-view): the script, its
 * service and its timer. The script reads the runners from Incus and writes them as a node exporter textfile, which the Gateway reads
 * from Prometheus for the CI runner view. The service is a oneshot that the timer starts, so only the timer is a role unit that
 * convergence starts and checks; the service is a role file. Beside it, the hold script that a proof copy's create and destroy call
 * (docs/reference/node-provisioning.md#proof-places-on-a-ci-node), with its folder of holds.
 */
final class CiRunnerStateFiles
{
    public function __construct(private readonly ?string $resources = null) {}

    public const string Script = '/usr/local/libexec/orbit-ci-runner-state';

    public const string Hold = '/usr/local/libexec/orbit-ci-runner-hold';

    public const string HoldState = '/var/lib/orbit/ci-hold';

    public const string Service = 'orbit-ci-runner-state.service';

    public const string Timer = 'orbit-ci-runner-state.timer';

    public const string Output = MetricsFootprint::ExporterTextfileDirectory.'/orbit_ci_runners.prom';

    /**
     * The script's folder. A Node that hosts no Instance has no `/usr/local/libexec`, and the file copy does not create its parent.
     *
     * @return list<array{path: string, owner: string, group: string, mode: string}>
     */
    public static function directories(): array
    {
        return [['path' => dirname(self::Script), 'owner' => 'root', 'group' => 'root', 'mode' => '0755']];
    }

    /** @return list<array{path: string, ensure: string, content: string, owner: string, group: string, mode: string}> */
    public static function files(?string $resources = null): array
    {
        $file = static fn (string $path, string $content, string $mode): array => ['path' => $path, 'ensure' => 'present', 'content' => $content,
            'owner' => 'root', 'group' => 'root', 'mode' => $mode];

        return [$file(self::Script, (string) file_get_contents(($resources ?? resource_path('ci-runner')).'/state.py'), '0755'),
            $file(self::Hold, self::holdScript($resources), '0755'),
            $file('/etc/systemd/system/'.self::Service, self::service(), '0644')];
    }

    /**
     * @return array{directories: list<array{path:string,owner:string,group:string,mode:string}>, files: list<array{path:string,ensure:string,content:string,owner:string,group:string,mode:string}>, units: list<array{name:string,unit:string}>}
     */
    public function runtime(): array
    {
        return ['directories' => self::directories(), 'files' => self::files($this->resources), 'units' => self::units()];
    }

    /** The hold script that this release installs. */
    public static function holdScript(?string $resources = null): string
    {
        return (string) file_get_contents(($resources ?? resource_path('ci-runner')).'/hold.py');
    }

    /** @return list<array{name: string, unit: string}> */
    public static function units(): array
    {
        return [['name' => self::Timer, 'unit' => self::timer()]];
    }

    /**
     * Root reads the Incus socket. The file system is read-only except the textfile folder, which `StateDirectory` creates when no
     * exporter package did, and a runtime folder for the Incus client's configuration.
     */
    private static function service(): string
    {
        return "# Managed by Orbit: ci-runner\n[Unit]\nDescription=Write the Orbit CI runner state for the node exporter\n"
            ."After=incus.service\n\n"
            ."[Service]\nType=oneshot\nExecStart=".self::Script.' '.self::Output."\nTimeoutStartSec=10\n"
            ."StateDirectory=prometheus/node-exporter\nRuntimeDirectory=orbit-ci-runner-state\nEnvironment=INCUS_CONF=/run/orbit-ci-runner-state\n"
            ."ProtectSystem=strict\nPrivateTmp=yes\nNoNewPrivileges=yes\nUMask=0022\n";
    }

    private static function timer(): string
    {
        return "# Managed by Orbit: ci-runner\n[Unit]\nDescription=Read the Orbit CI runner state every 15 seconds\n\n"
            ."[Timer]\nOnActiveSec=0\nOnUnitActiveSec=15s\nAccuracySec=1s\n\n"
            ."[Install]\nWantedBy=timers.target\n";
    }
}
