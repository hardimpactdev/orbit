<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * Every trace Metrics leaves on a node, and the proof Orbit owns each one.
 */
final readonly class MetricsFootprint
{
    public const string ExporterPackage = 'prometheus-node-exporter';

    public const string ExporterService = 'prometheus-node-exporter';

    public const string ExporterDropInDirectory = '/etc/systemd/system/prometheus-node-exporter.service.d';

    public const string ExporterDropIn = '/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf';

    /** The first line of the drop-in, and the only proof that Orbit wrote it. */
    public const string ExporterDropInMarker = '# Managed by Orbit: metrics';

    /** The port the exporter listens on, and the port its UFW rule opens. */
    public const string ExporterPort = '9100';

    /** The port Grafana listens on, and the port the Gateway upstream rule opens. */
    public const string PublicationPort = '3000';

    public const string ExporterFirewallComment = 'orbit:metrics-node-exporter';

    public const string PublicationFirewallComment = 'orbit:metrics-grafana-upstream';

    public const string PublicationFirewallDenyComment = 'orbit:metrics-grafana-isolation';

    public const string WireGuardInterface = 'orbit';

    public const string CadvisorBinaryPath = '/usr/local/bin/orbit-cadvisor';

    public const string CadvisorUnitPath = '/etc/systemd/system/orbit-cadvisor.service';

    public const string CadvisorService = 'orbit-cadvisor';

    /** The first line of the unit, and the only proof that Orbit owns it. */
    public const string CadvisorUnitMarker = '# Managed by Orbit: metrics-cadvisor';

    /** The port cAdvisor listens on, and the port its UFW rule opens. */
    public const string CadvisorPort = '9102';

    public const string CadvisorFirewallComment = 'orbit:metrics-cadvisor';
}
