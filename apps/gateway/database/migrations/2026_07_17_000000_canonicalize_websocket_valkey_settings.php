<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:cyclomatic-complexity */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('node_role')->where('role', 'websocket')->orderBy('id')->get() as $assignment) {
                $settings = json_decode(
                    (string) $assignment->settings,
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (! is_array($settings) || ! array_key_exists('redis_node_id', $settings)) {
                    continue;
                }

                $legacyNodeId = $settings['redis_node_id'];
                $canonicalNodeId = $settings['valkey_node_id'] ?? $legacyNodeId;

                if ($canonicalNodeId !== $legacyNodeId) {
                    throw new RuntimeException(
                        "Websocket role assignment [{$assignment->id}] has conflicting Redis and Valkey node settings.",
                    );
                }

                if (! is_int($legacyNodeId)) {
                    throw new RuntimeException(
                        "Websocket role assignment [{$assignment->id}] has an invalid legacy Redis node setting.",
                    );
                }

                $this->canonicalizeManagedService($legacyNodeId, (int) $assignment->id);

                unset($settings['redis_node_id']);
                $settings['valkey_node_id'] = $canonicalNodeId;

                DB::table('node_role')
                    ->where('id', $assignment->id)
                    ->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    private function canonicalizeManagedService(int $nodeId, int $assignmentId): void
    {
        $node = Node::query()->find($nodeId);

        if (! $node instanceof Node) {
            throw new RuntimeException(
                "Websocket role assignment [{$assignmentId}] references missing node [{$nodeId}].",
            );
        }

        $valkeyProcess = $this->managedProcesses($node, 'valkey')->first();

        if ($valkeyProcess instanceof Process) {
            $redisProcesses = $this->managedProcesses($node, 'redis');

            if ($redisProcesses->isNotEmpty()) {
                $redisProcess = $redisProcesses->first();

                if (
                    $redisProcesses->count() > 1
                    || $redisProcess->runtime !== $valkeyProcess->runtime
                ) {
                    throw new RuntimeException(
                        "Websocket role assignment [{$assignmentId}] cannot safely replace its duplicate managed Redis runtime.",
                    );
                }

                $runtimeConfig = $valkeyProcess->runtime_config;
                $runtimeConfig['replaces_runtime_unit'] = $this->runtimeUnit($redisProcess);
                $valkeyProcess->forceFill(['runtime_config' => $runtimeConfig])->save();
                Process::query()->whereKey($redisProcesses->modelKeys())->delete();
            }

            return;
        }

        $redisProcesses = $this->managedProcesses($node, 'redis');

        if ($redisProcesses->isEmpty()) {
            throw new RuntimeException(
                "Websocket role assignment [{$assignmentId}] references node [{$nodeId}] without a managed Redis or Valkey process.",
            );
        }

        if ($redisProcesses->count() > 1) {
            throw new RuntimeException(
                "Websocket role assignment [{$assignmentId}] references multiple managed Redis processes on node [{$nodeId}].",
            );
        }

        $redisProcess = $redisProcesses->first();

        if (! $redisProcess instanceof Process) {
            throw new RuntimeException(
                "Websocket role assignment [{$assignmentId}] references node [{$nodeId}] without a managed Redis process.",
            );
        }

        $processName = $redisProcess->name === 'redis' ? 'valkey' : $redisProcess->name;
        $nameConflict = Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->getKey())
            ->where('name', $processName)
            ->whereKeyNot($redisProcess->id)
            ->exists();

        if ($nameConflict) {
            throw new RuntimeException(
                "Websocket role assignment [{$assignmentId}] cannot rename its managed Redis process to [{$processName}].",
            );
        }

        $descriptor = app(ProcessServiceCatalog::class)->resolve(
            service: 'valkey',
            version: '8',
            runtime: $redisProcess->runtime,
            node: $node,
            processName: $processName,
        );
        $runtimeConfig = $descriptor->runtimeConfig;
        $runtimeConfig['replaces_runtime_unit'] = $this->runtimeUnit($redisProcess);

        $redisProcess->forceFill([
            'name' => $processName,
            'command' => $descriptor->command,
            'tool' => null,
            'runtime_config' => $runtimeConfig,
            'credentials' => $descriptor->credentials,
        ])->save();
    }

    /**
     * @return Collection<int, Process>
     */
    private function managedProcesses(Node $node, string $service): Collection
    {
        /** @var Collection<int, Process> $processes */
        $processes = Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->getKey())
            ->where('runtime_config->service', $service)
            ->get();

        return $processes;
    }

    private function runtimeUnit(Process $process): string
    {
        if ($process->runtime->value === 'docker-swarm') {
            $serviceName = $process->runtime_config['service_name'] ?? null;

            if (is_string($serviceName) && $serviceName !== '') {
                return $serviceName;
            }
        }

        return $process->name;
    }

    public function down(): void {}
};
