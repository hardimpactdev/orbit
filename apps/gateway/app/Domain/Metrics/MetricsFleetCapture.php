<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Record-only public capture; never starts an Operation or a host action. */
final readonly class MetricsFleetCapture
{
    public function __construct(private MetricsExporterProjection $exporters, private ServiceMetricsProjection $services,
        private AgentDelivery $agents, private NodeWorkerRollout $workers, private ProductionPhpRuntimeConfigRenderer $php,
        private ServiceMetricsConfigRenderer $service, private MetricsConfigurationRenderer $runtime) {}

    /** @return array<string,mixed> */
    public function capture(): array
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active)
            ->whereHas('node', static fn ($query) => $query->where('status', LifecycleStatus::Active))->with('node')->limit(2)->get();
        if ($assignments->count() !== 1) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Reconciliation needs one active Metrics assignment.', 409);
        }
        $assignment = $assignments->sole();
        $metrics = $assignment->node;
        $members = [];
        $targets = [];
        $services = [];
        foreach ($this->exporters->for($metrics) as $item) {
            $node = $item->node;
            if (! is_string($node->wireguard_ip) || filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('metrics.exporter_address_invalid', 'An exporter needs its recorded WireGuard IPv4.', 409);
            }
            $service = $this->services->forNode($metrics, $node);
            $members[] = ['node_id' => $node->id, 'name' => $node->name, 'address' => $node->wireguard_ip,
                'agent_sha256' => $this->agents->pin($node), 'worker' => $this->workers->allows($node),
                'selected' => $item->selection->selected, 'selection_reason' => $item->selection->reason->value,
                'caddy' => $service->caddy, 'fpm' => $service->fpm, 'hosts' => $service->hosts,
                'instance_ids' => array_map(static fn ($instance): int => $instance->id, $service->instances),
                'service' => $this->service($service, (string) $metrics->wireguard_ip), 'pools' => $this->pools($service)];
            if ($item->selection->selected) {
                $targets[] = ['name' => $node->name, 'address' => $node->wireguard_ip];
            }
            $services[] = $service;
        }
        usort($members, static fn (array $a, array $b): int => $a['node_id'] <=> $b['node_id']);
        usort($targets, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $plan = ['schema_version' => 1, 'assignment_id' => $assignment->id, 'metrics_node_id' => $metrics->id,
            'metrics_address' => $metrics->wireguard_ip, 'fleet' => $members,
            'runtime_files' => $this->runtime->publicFiles($targets, $services)];

        return [...$plan, 'digest' => self::digest($plan)];
    }

    /** @param array<string,mixed> $plan
     * @return list<array<string,mixed>>
     */
    public static function members(array $plan): array
    {
        $members = $plan['fleet'] ?? null;
        if (! is_array($members) || ! array_is_list($members) || $members === [] || count($members) > 256) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The fleet capture needs its complete member list.', 422);
        }
        $result = [];
        foreach ($members as $member) {
            $member = ValidatedData::object($member);
            if (! is_int($member['node_id'] ?? null) || $member['node_id'] < 1 || ! is_bool($member['worker'] ?? null)) {
                throw new ResourceOperationException('metrics.snapshot_changed', 'The fleet member identity is invalid.', 422);
            }
            $result[] = $member;
        }

        return $result;
    }

    /** @param array<string,mixed> $snapshot */
    public static function assertDigest(array $snapshot): void
    {
        $fields = ['schema_version', 'assignment_id', 'metrics_node_id', 'metrics_address', 'fleet', 'runtime_files', 'digest'];
        if (array_diff($fields, array_keys($snapshot)) !== [] || array_diff(array_keys($snapshot), $fields) !== []
            || $snapshot['schema_version'] !== 1 || ! is_int($snapshot['assignment_id']) || $snapshot['assignment_id'] < 1
            || ! is_int($snapshot['metrics_node_id']) || $snapshot['metrics_node_id'] < 1
            || ! is_string($snapshot['metrics_address']) || filter_var($snapshot['metrics_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_array($snapshot['runtime_files']) || $snapshot['runtime_files'] === []
            || array_key_exists('/etc/orbit/metrics/grafana/admin-password', $snapshot['runtime_files'])
            || array_any($snapshot['runtime_files'], static fn (mixed $value): bool => ! is_string($value))) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The public Metrics capture is incomplete.', 422);
        }
        foreach (self::members($snapshot) as $member) {
            $keys = ['node_id', 'name', 'address', 'agent_sha256', 'worker', 'selected', 'selection_reason', 'caddy', 'fpm', 'hosts', 'instance_ids', 'service', 'pools'];
            if (array_diff($keys, array_keys($member)) !== [] || array_diff(array_keys($member), $keys) !== []
                || ! is_string($member['name']) || ! is_string($member['address']) || filter_var($member['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || ! is_string($member['agent_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $member['agent_sha256']) !== 1
                || ! is_string($member['selection_reason']) || ExporterSelectionReason::tryFrom($member['selection_reason']) === null
                || ! is_bool($member['selected']) || ! is_bool($member['caddy']) || ! is_bool($member['fpm'])
                || ! is_array($member['hosts']) || ! array_is_list($member['hosts']) || array_any($member['hosts'], static fn (mixed $value): bool => ! is_string($value))
                || ! is_array($member['instance_ids']) || ! array_is_list($member['instance_ids']) || array_any($member['instance_ids'], static fn (mixed $value): bool => ! is_int($value) || $value < 1)
                || ! is_array($member['service']) || ! is_array($member['pools']) || ! array_is_list($member['pools'])) {
                throw new ResourceOperationException('metrics.snapshot_changed', 'The public Metrics member is incomplete.', 422);
            }
            $serviceKeys = ['config', 'unit', 'rules', 'active', 'enabled', 'binary'];
            if (array_diff($serviceKeys, array_keys($member['service'])) !== [] || array_diff(array_keys($member['service']), $serviceKeys) !== []
                || ! is_bool($member['service']['active']) || ! is_bool($member['service']['enabled']) || ! is_bool($member['service']['binary'])
                || ! is_array($member['service']['rules']) || ! array_is_list($member['service']['rules'])
                || ($member['service']['config'] !== null && ! is_string($member['service']['config']))
                || ($member['service']['unit'] !== null && ! is_string($member['service']['unit']))) {
                throw new ResourceOperationException('metrics.snapshot_changed', 'The public Metrics service plan is incomplete.', 422);
            }
            foreach ($member['pools'] as $pool) {
                if (! is_array($pool) || array_diff(['instance_id', 'inspect', 'enabled', 'disabled'], array_keys($pool)) !== []
                    || ! is_int($pool['instance_id']) || ! in_array($pool['instance_id'], $member['instance_ids'], true)
                    || ! is_array($pool['inspect']) || ! is_array($pool['enabled']) || ! is_array($pool['disabled'])) {
                    throw new ResourceOperationException('metrics.snapshot_changed', 'The public Metrics pool plan is incomplete.', 422);
                }
            }
        }
        $digest = $snapshot['digest'] ?? null;
        unset($snapshot['digest']);
        if (! is_string($digest) || ! hash_equals(self::digest($snapshot), $digest)) {
            throw new ResourceOperationException('metrics.snapshot_changed', 'The admitted Metrics capture changed.', 409);
        }
    }

    /** @param array<string,mixed> $value */
    public static function digest(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @return array<string,mixed> */
    private function service(ServiceMetricsNode $node, string $metricsAddress): array
    {
        $fpm = $node->fpm && $node->instances !== [];
        $rules = [];
        foreach (['caddy' => $node->caddy, 'fpm' => $fpm] as $kind => $enabled) {
            if (! $enabled) {
                continue;
            }
            foreach (['allow', 'deny'] as $action) {
                $rules[] = [$action, 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $action === 'allow' ? $metricsAddress : 'any',
                    'to', $node->node->wireguard_ip, 'port', $kind === 'caddy' ? ServiceMetricsConfigRenderer::CaddyPort : ServiceMetricsConfigRenderer::FpmPort,
                    'comment', 'orbit:metrics-service-'.$kind.'-'.$action];
            }
        }

        return ['config' => $fpm ? $this->service->fpm($node) : null, 'unit' => $fpm ? $this->service->unit() : null,
            'rules' => $rules, 'active' => $fpm, 'enabled' => $fpm, 'binary' => $fpm];
    }

    /** @return list<array<string,mixed>> */
    private function pools(ServiceMetricsNode $node): array
    {
        $pools = [];
        foreach ($node->instances as $instance) {
            $identity = ProductionPhpRuntimeIdentity::from($instance);
            $modes = [];
            foreach ([true, false] as $enabled) {
                $applications = RouteWebRoot::servedApplications($instance);
                $configuration = $this->php->render($identity, $enabled, applications: $applications);
                $initial = $this->php->render($identity, $enabled, initialRelease: true, applications: $applications);
                $modes[$enabled ? 'enabled' : 'disabled'] = ['mode' => 'monitor', 'user' => $identity->user, 'home' => $identity->home,
                    'version' => $identity->version, 'marker' => $identity->marker(), 'configuration' => ['main' => $configuration->main,
                        'pool' => $configuration->pool, 'local_defaults' => $configuration->localDefaults, 'master_ini' => $configuration->masterIni,
                        'unit' => $configuration->unit, 'initial_pool' => $initial->pool],
                    'application' => $identity->applicationDirectory(), 'initial_application' => $identity->applicationDirectory(initialRelease: true)];
            }
            $pools[] = ['instance_id' => $instance->id, 'inspect' => ['user' => $identity->user, 'version' => $identity->version,
                'marker' => $identity->marker()], ...$modes];
        }

        return $pools;
    }
}
