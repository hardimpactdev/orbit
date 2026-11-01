<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Processes\RecordProcessIntentAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;

/** Role-owned runtime intent. Capture never discovers accounts or executes a host command. */
final readonly class NodeRoleRuntimeInput
{
    public function __construct(private AgentDelivery $agents, private ProcessTargetResolver $targets,
        private DockerProcessRenderer $docker, private RecordProcessIntentAction $processes, private ProcessSpecification $specifications,
        private AnalyticsRoleSettingsRepository $analytics, private AnalyticsStorageProcessGuard $storage,
        private AnalyticsSecretManager $analyticsSecrets, private MetricsCredentialManager $metricsSecrets,
        private WebSocketCredentialManager $websocketSecrets, private MetricsExporterProjection $exporters,
        private ServiceMetricsProjection $services) {}

    /** Record-only preparation runs in the parent admission transaction, before immutable capture. */
    public function prepare(Node $node, NodeRole $assignment, ?NodeEnrollmentCandidate $candidate = null): void
    {
        $this->assignment($node, $assignment);
        if ($candidate !== null && $candidate->nodeId !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The runtime candidate belongs to another Node.', 422);
        }
        if ($assignment->role === RoleName::Metrics) {
            $this->metricsSecrets->passwordForConvergence($node);
        } elseif ($assignment->role === RoleName::WebSocket) {
            $this->websocketSecrets->ensure($node);
        } elseif ($assignment->role === RoleName::Analytics) {
            $settings = $this->analytics->find($node) ?? throw new ResourceOperationException('analytics.settings_missing', 'Analytics storage intent is missing.', 422);
            $storage = $this->storage->assert($settings->postgresProcessId, $settings->clickhouseProcessId);
            $data = PlausibleProcess::data($node, $this->analytics->version($node),
                AnalyticsStorageConnection::from($storage['postgres'], $storage['clickhouse']), $this->analyticsSecrets->secretKeyBase($node), $candidate);
            $target = $candidate === null ? $this->targets->forNodeAdmission($node) : $this->targets->forNodeCapture($node, $candidate);
            try {
                $this->processes->execute($data, $target);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode !== 'process.name_taken') {
                    throw $exception;
                }
                $process = $this->plausible($node);
                if ($process->runtime !== ProcessRuntime::Docker) {
                    throw $exception;
                }
                $process->fill([...$this->specifications->attributes($data, $target),
                    'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Provisioning,
                    'error_code' => null, 'failed_step' => null])->save();
            }
        }
    }

    /** @return array<string,mixed>|null */
    public function capture(Node $node, NodeRole $assignment): ?array
    {
        $this->assignment($node, $assignment);
        if (! in_array($assignment->role, [RoleName::Metrics, RoleName::Analytics, RoleName::WebSocket], true)) {
            return null;
        }
        $render = app(RouteRenderContext::class);
        $candidate = $render->enrollment();
        $target = $candidate?->nodeId === $node->id ? $candidate : $node;
        $input = ['role' => $assignment->role->value, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'agent_sha256' => $this->agents->pin($target), 'material' => ['$secret' => 'node.role_runtime', 'id' => $node->id]];
        if ($assignment->role === RoleName::WebSocket) {
            return [...$input, 'user' => $render->user($node), 'install_path' => config('orbit.websocket.install_path'),
                'repository' => config('orbit.websocket.repository'), 'ref' => config('orbit.websocket.ref'), 'port' => config('orbit.websocket.port')];
        }
        if ($assignment->role === RoleName::Metrics) {
            $targets = [];
            $fleet = [];
            foreach ($this->exporters->for($node) as $item) {
                $target = $item->node;
                if ($item->selection->selected) {
                    $targets[] = ['name' => $render->name($target), 'address' => $render->wireguardIp($target)];
                }
                $service = $this->services->forNode($node, $target);
                $desired = $candidate?->nodeId === $target->id ? $candidate : $target;
                $fleet[] = ['node_id' => $target->id, 'agent_sha256' => $this->agents->pin($desired),
                    'address' => $render->wireguardIp($target), 'name' => $render->name($target), 'selected' => $item->selection->selected,
                    'caddy' => $service->caddy, 'fpm' => $service->fpm, 'hosts' => $service->hosts,
                    'instance_ids' => array_map(static fn ($instance): int => $instance->id, $service->instances)];
            }

            return [...$input, 'address' => $render->wireguardIp($node), 'targets' => $targets, 'fleet' => $fleet];
        }
        $process = $this->plausible($node);
        $settings = $this->analytics->find($node) ?? throw new ResourceOperationException('analytics.settings_missing', 'Analytics storage intent is missing.', 422);
        $storage = $this->storage->assert($settings->postgresProcessId, $settings->clickhouseProcessId);
        $clickhouse = $storage['clickhouse'];
        $clickhouse->loadMissing('owner');
        $storageNode = $clickhouse->owner;
        if (! $storageNode instanceof Node) {
            throw new ResourceOperationException('analytics.process_not_node', 'ClickHouse requires its exact Node owner.', 422);
        }

        return [...$input, 'installation' => $this->installation($node, $process),
            'health_url' => 'http://'.$render->wireguardIp($node).':'.PlausibleProcess::PORT.'/api/health',
            'clickhouse' => ['node_id' => $storageNode->id, 'agent_sha256' => $this->agents->pin($storageNode), 'process_id' => $clickhouse->id]];
    }

    /** @return array<string,mixed>|null */
    public function removal(Node $node, RoleName $role, bool $purge = false): ?array
    {
        $input = ['role' => $role->value, 'node_id' => $node->id, 'agent_sha256' => $this->agents->pin($node), 'purge_data' => $purge];

        return match ($role) {
            RoleName::Metrics => [...$input, 'address' => $node->wireguard_ip],
            RoleName::WebSocket => [...$input, 'install_path' => config('orbit.websocket.install_path')],
            RoleName::Analytics => [...$input, 'process_id' => $this->plausible($node)->id,
                'name' => $this->docker->containerName($this->plausible($node))],
            default => null,
        };
    }

    /** @return array<string,mixed> */
    private function installation(Node $node, Process $process): array
    {
        $candidate = app(RouteRenderContext::class)->enrollment();
        $target = $candidate?->nodeId === $node->id ? $this->targets->forNodeCapture($node, $candidate) : $this->targets->forInstallation($process);

        return ['process_id' => $process->id, 'agent_sha256' => $this->agents->pin($candidate?->nodeId === $node->id ? $candidate : $node), 'runtime' => 'docker',
            'name' => $this->docker->containerName($process), 'desired' => 'running', 'on_demand' => false,
            'retired' => null, 'spec' => $this->docker->specHash($process, $target)];
    }

    private function plausible(Node $node): Process
    {
        return Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', PlausibleProcess::NAME)->first()
            ?? throw new ResourceOperationException('analytics.runtime_missing', 'Analytics runtime intent must be recorded before capture.', 409);
    }

    private function assignment(Node $node, NodeRole $assignment): void
    {
        if ($assignment->node_id !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The runtime assignment belongs to another Node.', 422);
        }
    }
}
