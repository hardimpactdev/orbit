<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;

/** Renders target-owned monitoring intent and exposes only safe scalar snapshots. */
final readonly class MetricsServiceExecution implements OperationFamilyExecution
{
    public function __construct(private MetricsChildIntent $children, private ProductionPhpRuntimeConfigRenderer $php, private ServiceMetricsConfigRenderer $renderer) {}

    public function context(Operation $operation): array
    {
        $descriptors = [];
        $instances = [];
        foreach ($operation->input['instance_ids'] as $id) {
            $instance = Instance::query()->findOrFail($id);
            if ($instance->node_id !== $operation->node_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The PHP runtime has another Node owner.', 422);
            }
            $instances[] = $instance;
        }
        $snapshot = $operation->kind === 'metrics.service_snapshot';
        $restore = $operation->kind === 'metrics.service_restore';
        $source = $restore ? $this->children->snapshot($operation) : null;
        if ($source !== null && ($source->kind !== 'metrics.service_snapshot' || $source->input['instance_ids'] !== $operation->input['instance_ids'])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The service snapshot has another owner.', 422);
        }
        foreach ($instances as $instance) {
            $identity = ProductionPhpRuntimeIdentity::from($instance);
            $key = 'pool-'.$instance->id;
            if ($snapshot) {
                $descriptors[] = $this->descriptor($key, 'php.inspect-production-pool', ['user' => $identity->user, 'version' => $identity->version, 'marker' => $identity->marker()]);
                continue;
            }
            $enabled = $restore ? ($source->state['steps'][$key]['enabled'] ?? null) : $operation->input['fpm'];
            if (! is_bool($enabled)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The service snapshot has no proven PHP state.', 422);
            }
            $applications = RouteWebRoot::servedApplications($instance);
            $configuration = $this->php->render($identity, $enabled, applications: $applications);
            $initial = $this->php->render($identity, $enabled, initialRelease: true, applications: $applications);
            $descriptors[] = $this->descriptor($key, 'php.converge-production-runtime', [
                'mode' => 'monitor', 'user' => $identity->user, 'home' => $identity->home, 'version' => $identity->version, 'marker' => $identity->marker(),
                'configuration' => ['main' => $configuration->main, 'pool' => $configuration->pool, 'local_defaults' => $configuration->localDefaults,
                    'master_ini' => $configuration->masterIni, 'unit' => $configuration->unit, 'initial_pool' => $initial->pool],
                'application' => $identity->applicationDirectory(), 'initial_application' => $identity->applicationDirectory(initialRelease: true),
            ]);
        }
        $state = null;
        if (! $snapshot) {
            if ($restore) {
                $state = $source->result;
                $state['rules'] = array_map(static fn (string $rule): array => json_decode($rule, true, flags: JSON_THROW_ON_ERROR), $state['rules']);
                $state = array_intersect_key($state, array_flip(['config', 'unit', 'rules', 'active', 'enabled', 'binary']));
            } else {
                $node = Node::query()->findOrFail($operation->node_id);
                $target = new ServiceMetricsNode($node, $operation->input['caddy'], $operation->input['fpm'], $instances);
                $fpm = $target->fpm && $instances !== [];
                $rules = [];
                foreach (['caddy' => $target->caddy, 'fpm' => $fpm] as $kind => $enabled) {
                    if (! $enabled) { continue; }
                    foreach (['allow', 'deny'] as $action) {
                        $rules[] = [$action, 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $action === 'allow' ? $operation->input['metrics_address'] : 'any',
                            'to', $node->wireguard_ip, 'port', $kind === 'caddy' ? ServiceMetricsConfigRenderer::CaddyPort : ServiceMetricsConfigRenderer::FpmPort,
                            'comment', 'orbit:metrics-service-'.$kind.'-'.$action];
                    }
                }
                $state = ['config' => $fpm ? $this->renderer->fpm($target) : null, 'unit' => $fpm ? $this->renderer->unit() : null,
                    'rules' => $rules, 'active' => $fpm, 'enabled' => $fpm, 'binary' => $fpm];
            }
        }
        $descriptors[] = $this->descriptor('service-exporter', 'metrics.service-runtime', $snapshot ? ['operation' => 'snapshot'] : ['operation' => 'apply', 'state' => $state]);
        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $entry) {
            if ($entry['key'] === $run['key']) { return $entry['input']; }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The service effect is outside the admitted intent.', 422);
    }
    public function beforeRun(Operation $operation, array $run): void
    {
        foreach ($this->context($operation)['descriptors'] as $entry) {
            if ($entry['key'] === $run['key']) { return; }
            if (! $operation->steps()->where('name', $entry['step'])->where('status', 'succeeded')->exists()) {
                throw new ResourceOperationException('operation.completion_unproven', 'The preceding monitoring step is unproven.', 409);
            }
        }
    }
    public function received(Operation $operation, array $run, array $receipt): array
    {
        if (isset($receipt['error_code'])) { return $receipt; }
        if ($run['name'] === 'php.inspect-production-pool') {
            $id = (int) substr($run['key'], 5);
            $identity = ProductionPhpRuntimeIdentity::from(Instance::query()->findOrFail($id));
            $pool = $receipt['pool'] ?? '';
            if (! is_string($pool) || ! is_string($receipt['fingerprint'] ?? null)) {
                throw new ResourceOperationException('metrics.service_inspection_failed', 'The PHP pool inspection is invalid.', 502);
            }
            return ['enabled' => str_contains($pool, "pm.status_path = /orbit-fpm-status\n") && str_contains($pool, "pm.status_listen = {$identity->socket}.status\n"), 'fingerprint' => $receipt['fingerprint']];
        }
        if ($operation->kind !== 'metrics.service_snapshot') { return ['applied' => true]; }
        if (! is_array($receipt['rules'] ?? null)) {
            throw new ResourceOperationException('metrics.service_inspection_failed', 'The service snapshot is invalid.', 502);
        }
        $receipt['rules'] = array_map(static fn (array $rule): string => json_encode($rule, JSON_THROW_ON_ERROR), $receipt['rules']);
        return $receipt;
    }
    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}
    public function cancellationBoundary(Operation $operation): ?array { return null; }
    private function descriptor(string $key, string $name, array $input): array
    {
        return ['key' => $key, 'step' => $key, 'transport' => 'agent', 'name' => $name, 'input' => $input, 'resumable' => true, 'required' => true];
    }
}
