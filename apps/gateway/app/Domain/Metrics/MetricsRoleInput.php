<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RouteRenderContext;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Full public specifications for the official native role workflow. */
final readonly class MetricsRoleInput
{
    /** @return array<string,mixed> */
    public function capture(NodeRole $assignment, bool $enabled, bool $purge): array
    {
        $node = $assignment->node;
        $gateway = app(MetricsGatewayResolver::class)->resolve();
        $ssh = app(SwampSshExecutionInput::class)->forNode($node);
        $certificates = app(NodeRoleCertificateInput::class)->capture($node, RoleName::Metrics, removing: ! $enabled);
        $files = ValidatedData::stringMap(app(MetricsFleetCapture::class)->captureFor($assignment, $enabled)['runtime_files']);
        // Member Caddy plans render under the same prospective role certificates as the Gateway site.
        [$caddy, $fleet] = app(RouteRenderContext::class)->renderRoleCertificates($certificates, function () use ($gateway, $node, $enabled, $ssh, $files): array {
            $fleet = [];
            foreach (app(MetricsExporterProjection::class)->for($node) as $item) {
                $target = $item->node;
                $selected = $enabled && $item->selection->selected;
                $fleet[] = ['exporter_node_id' => $target->id, 'enabled' => $selected, 'metrics_address' => $node->wireguard_ip,
                    'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target),
                    'runtime' => ['ssh_execution' => $ssh, 'configuration' => $files['/etc/orbit/metrics/prometheus.yml']],
                    'service' => $this->service($node, $target, $selected, $gateway)];
            }

            return [app(NativeCaddyPublicationInput::class)->forNode($gateway), $fleet];
        });
        // The role row is stored before this render, so the complete private DNS already holds or drops metrics.orbit.
        $dns = [];
        foreach (PrivateDnsPublicationNodes::query()->get() as $dnsNode) {
            $dns[] = app(PrivateDnsPublication::class)->entry($dnsNode);
        }
        $dirs = array_values(array_unique(array_map(dirname(...), array_keys($files))));
        usort($dirs, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

        return ['enabled' => $enabled, 'purge_data' => $purge, 'assignment_id' => $assignment->id, 'ssh_execution' => $ssh,
            'directories' => $dirs, 'files' => array_map(static fn (string $path, string $content): array => ['path' => $path, 'content' => $content], array_keys($files), array_values($files)),
            'plan' => app(MetricsContainerPlan::class)->for($assignment->id, (string) $ssh['target']['host'], $files),
            'fleet' => $fleet, 'caddy' => $caddy, 'dns' => $dns, 'certificates' => $certificates,
            'gateway_ssh' => app(SwampSshExecutionInput::class)->forNode($gateway),
            'firewall' => ['port' => 3000, 'source' => $gateway->wireguard_ip, 'action' => 'allow', 'ensure' => $enabled ? 'present' : 'absent'],
            'purge_volumes' => $purge ? ['orbit-metrics-prometheus-data', 'orbit-metrics-grafana-data'] : []];
    }

    /**
     * The role publishes and withdraws the Gateway's Caddyfile itself, after its certificate; a Gateway member plan would
     * publish metrics.orbit before the certificate exists, or put it back after the withdrawal.
     *
     * @return array<string,mixed>
     */
    private function service(Node $metrics, Node $target, bool $selected, Node $gateway): array
    {
        $service = app(MetricsFleetCapture::class)->forExporter($metrics, $target, $selected);

        return $target->is($gateway) ? [...$service, 'caddy' => null] : $service;
    }
}
