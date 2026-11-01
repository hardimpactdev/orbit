<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RouteRenderContext;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Full public specifications for the official native role workflow. */
final readonly class MetricsRoleInput
{
    /** @param array<int,array<string,mixed>> $beforeDns
     * @return array<string,mixed>
     */
    public function capture(NodeRole $assignment, bool $enabled, bool $purge, array $beforeDns): array
    {
        $node = $assignment->node;
        $gateway = app(MetricsGatewayResolver::class)->resolve();
        $ssh = app(SwampSshExecutionInput::class)->forNode($node);
        $certificates = app(NodeRoleCertificateInput::class)->capture($node, RoleName::Metrics, removing: ! $enabled);
        $caddy = app(RouteRenderContext::class)->renderRoleCertificates($certificates,
            fn (): array => app(NativeCaddyPublicationInput::class)->forNode($gateway));
        $files = ValidatedData::stringMap(app(MetricsFleetCapture::class)->captureFor($assignment, $enabled)['runtime_files']);
        $fleet = [];
        foreach (app(MetricsExporterProjection::class)->for($node) as $item) {
            $target = $item->node;
            $selected = $enabled && $item->selection->selected;
            $fleet[] = ['exporter_node_id' => $target->id, 'enabled' => $selected, 'metrics_address' => $node->wireguard_ip,
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target),
                'runtime' => ['ssh_execution' => $ssh, 'configuration' => $files['/etc/orbit/metrics/prometheus.yml']],
                'compose_only' => true, 'service' => app(MetricsFleetCapture::class)->forExporter($node, $target, $selected)];
        }
        $dns = [];
        foreach (PrivateDnsPublicationNodes::query()->get() as $dnsNode) {
            $dns[] = ['node_id' => $dnsNode->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($dnsNode),
                'before' => $beforeDns[$dnsNode->id], 'after' => app(NativeRoutePublicationInput::class)->dns($dnsNode), 'alternatives' => []];
        }
        $dirs = array_values(array_unique(array_map(dirname(...), array_keys($files))));
        usort($dirs, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

        return ['enabled' => $enabled, 'purge_data' => $purge, 'assignment_id' => $assignment->id, 'ssh_execution' => $ssh,
            'directories' => $dirs, 'files' => array_map(static fn (string $path, string $content): array => ['path' => $path, 'content' => $content], array_keys($files), array_values($files)),
            'fleet' => $fleet, 'caddy' => $caddy, 'dns' => $dns, 'certificates' => $certificates,
            'gateway_ssh' => app(SwampSshExecutionInput::class)->forNode($gateway),
            'firewall' => ['port' => 3000, 'source' => $gateway->wireguard_ip, 'action' => 'allow', 'ensure' => $enabled ? 'present' : 'absent'],
            'purge_volumes' => $purge ? ['orbit-metrics-prometheus-data', 'orbit-metrics-grafana-data'] : []];
    }
}
