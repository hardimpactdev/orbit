<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\SwampRoleUnits;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class AdmitNodeBaseInventoryAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private ManagedNodeEligibility $eligibility,
        private SwampSshExecutionInput $ssh,
        private SwampCommandStep $swamp,
    ) {}

    public function execute(Node $node, string $requestId): Operation
    {
        $profile = match ($node->id) {
            4 => 'router',
            17 => 'services',
            209 => 'swamp',
            default => null,
        };
        if ($profile === null || ! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.base_inventory_unsupported', 'Base inventory is unavailable for this Node.', 422);
        }
        if (($replay = $this->idempotency->replay('node.base_inventory')) !== null) {
            return $replay;
        }

        $ssh = $this->ssh->forNode($node);
        $verifier = $node->agent_secret_hash;
        if ($ssh['target']['host_keys'] === null || ! is_string($verifier) || preg_match('/\A[a-f0-9]{64}\z/D', $verifier) !== 1) {
            throw new ResourceOperationException('node.base_inventory_unavailable', 'Base inventory requires a pinned target and an agent verifier.', 503);
        }
        $expected = $this->expectedDigests($node, $profile);
        $port = $profile === 'services' ? config('orbit.websocket.port') : 0;
        if (! is_int($port) || $port < 0 || $port > 65535) {
            throw new ResourceOperationException('node.base_inventory_unavailable', 'The recorded WebSocket port is unavailable.', 503);
        }
        $bound = [
            'node_id' => $node->id, 'profile' => $profile, 'target' => $ssh['target'], 'expected' => $expected,
            'websocket_port' => $port, 'backing_containers' => $profile === 'services' ? $this->backingContainers($node) : [],
        ];

        return DB::transaction(function () use ($node, $requestId, $ssh, $verifier, $bound): Operation {
            $operation = $this->operations->execute('node.base_inventory', 'node', $node->id, $node->id, [
                'participant_node_ids' => array_values(array_unique([$node->id, $ssh['control_node_id']])),
                'profile' => $bound['profile'],
            ], 'operator', $requestId);
            if ($operation->wasRecentlyCreated) {
                $value = json_encode(['admission' => [...$bound, 'operation_id' => $operation->id], 'verifier' => $verifier], JSON_THROW_ON_ERROR);
                $copy = $this->swamp->executionCopy('orbit-control-inputs', 'node-base-inventory-'.$operation->id.'-verifier', $value);
                $operation->update(['input' => [...$operation->input, 'execution_secrets' => ['verifier' => $copy]]]);
            }

            return $operation;
        });
    }

    /** @return list<string> */
    private function backingContainers(Node $node): array
    {
        $ids = DatabaseServer::query()->where('node_id', $node->id)->where('status', LifecycleStatus::Active)
            ->whereNotNull('process_id')->pluck('process_id')->all();
        $analytics = NodeRole::query()->where('role', RoleName::Analytics)->where('status', LifecycleStatus::Active)->with('node')->first();
        if ($analytics !== null) {
            $settings = app(AnalyticsRoleSettingsRepository::class)->find($analytics->node);
            if ($settings !== null) {
                array_push($ids, $settings->postgresProcessId, $settings->clickhouseProcessId);
            }
        }
        $processes = $node->processes()->where('runtime', ProcessRuntime::Docker)->where(function ($query) use ($ids, $analytics, $node): void {
            $query->whereIn('id', $ids);
            if ($analytics?->node_id === $node->id) {
                $query->orWhere('name', PlausibleProcess::NAME);
            }
        })->orderBy('id')->get(['id', 'name']);
        $renderer = app(DockerProcessRenderer::class);

        return array_values($processes->map($renderer->containerName(...))->all());
    }

    /** @return array<string,string> */
    private function expectedDigests(Node $node, string $profile): array
    {
        $intent = app(NodeDesiredConvergeInput::class)->baseIntent($node);
        $agent = ValidatedData::object($intent['agent']);
        $expected = [
            '/usr/local/bin/orbit-agent' => ValidatedData::string($agent['sha256']),
            '/etc/orbit/agent/config.toml' => hash('sha256', ValidatedData::string($agent['configuration'])),
            '/etc/orbit/agent/ca.pem' => hash('sha256', ValidatedData::string($agent['certificate'])),
            '/etc/systemd/system/orbit-agent.service' => hash('sha256', ValidatedData::string($agent['unit'])),
        ];
        foreach (['kernel', 'updates'] as $part) {
            $policy = ValidatedData::object($intent[$part]);
            foreach (is_array($policy['files']) ? $policy['files'] : [] as $file) {
                $file = ValidatedData::stringMap($file);
                $expected[$file['path']] = hash('sha256', $file['content']);
            }
        }
        $roles = $intent['role_units'];
        foreach (is_array($roles) ? $roles : [] as $unit) {
            $unit = ValidatedData::stringMap($unit);
            $expected['/etc/systemd/system/'.$unit['name']] = hash('sha256', $unit['content']);
        }
        if ($profile === 'swamp') {
            $expected['/etc/systemd/system/orbit-swamp-serve.service'] = hash('sha256', SwampRoleUnits::for($node->user, $node->id)['serve']);
        }
        $executor = $intent['executor'] ?? null;
        if ($profile === 'services' && is_array($executor) && $executor['skip'] === null && $executor['plan_error'] === null) {
            $expected[ValidatedData::string($executor['path'])] = ValidatedData::string($executor['sha256']);
            foreach ([
                '/etc/orbit/executor/gateway.pub' => 'gateway_keys',
                '/etc/orbit/executor/node' => 'node',
                '/etc/orbit/executor/enabled' => 'enabled',
                '/etc/orbit/executor/restore-threads' => 'restore_threads',
                '/etc/systemd/system/orbit-executor.service' => 'unit',
                '/etc/sudoers.d/orbit-executor-relay' => 'sudoers',
            ] as $path => $key) {
                $expected[$path] = hash('sha256', ValidatedData::string($executor[$key]));
            }
        }

        return $expected;
    }
}
