<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records a complete, single-Node intent. Contains no runtime execution. */
final readonly class ProcessOperationAdmission
{
    public function __construct(private StartOperationAction $operations, private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd, private DockerProcessRenderer $docker, private ManagedUserAccountResolver $accounts) {}

    public function execute(Process $process, string $verb, bool $created = false, bool $preserveRecord = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        if (! array_key_exists('process.'.$verb, OperationKinds::definitions())) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Process kind requires the shared child and result contracts.', 409);
        }

        return DB::transaction(function () use ($process, $verb, $created, $preserveRecord, $caller, $requestId): Operation {
            $input = $this->inputFor($process, $verb, $created, $preserveRecord, $caller);
            $nodeId = ValidatedData::integer($input['node_id']);
            $writer = app(DirectOperation::class)->writer('process.'.$verb, $input, $nodeId);
            if ($writer === 'direct') {
                $input['agent_sha256'] = app(AgentDelivery::class)->pin(Node::query()->findOrFail($nodeId));
            }
            $operation = $this->operations->execute('process.'.$verb, 'process', $process->id, $nodeId, $input, 'operator', $requestId, expectedWriter: $writer);
            $process->update(['status' => $verb === 'destroy' ? LifecycleStatus::Removing : LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);

            return $operation;
        });
    }

    /** @return array<string, mixed> */
    public function inputFor(Process $process, string $verb, bool $created = false, bool $preserveRecord = false, ?Node $caller = null): array
    {
        $target = match ($verb) {
            'destroy' => $this->targets->forRemoval($process),
            'start', 'restart' => $this->targets->forStart($process),
            default => $this->targets->forInstallation($process),
        };
        if (! in_array($verb, ['stop', 'destroy'], true)) {
            app(ProcessSwampSafety::class)->assertAllowed($target->node);
        }
        if ($process->isVpDev() && in_array($verb, ['create', 'update', 'start', 'restart'], true)) {
            $instance = $target->instance;
            if ($instance !== null) {
                app(VitePortAllocator::class)->assign($instance, recheck: true);
                $target = $this->targets->forInstallation($process);
            }
        }
        $systemd = $process->runtime === ProcessRuntime::Systemd;
        $name = $systemd ? $this->systemd->unitName($process) : $this->docker->containerName($process);
        $input = ['process_id' => $process->id, 'node_id' => $target->node->id, 'name' => $name,
            'runtime' => $process->runtime->value, 'variant' => $process->isVpDev() && in_array($verb, ['create', 'update', 'start', 'restart'], true) ? 'vite' : $process->runtime->value, 'desired' => $process->desired_state->value,
            'on_demand' => $target->onDemandHostStart, 'created' => $created, 'preserve_record' => $preserveRecord,
            'caller_node_id' => $caller?->id,
            'vite' => $verb !== 'stop' && $systemd && $process->isVpDev() ? ['path' => SystemdProcessRenderer::viteEnvironmentPath($process->owner_id), 'instance_id' => $process->owner_id] : null];
        if ($target->productionReleaseLayout && ($process->desired_state === DesiredProcessState::Running || in_array($verb, ['start', 'restart'], true)) && $verb !== 'destroy' && $verb !== 'stop') {
            $input['directory'] = $target->defaultWorkingDirectory;
        }
        if (in_array($verb, ['create', 'update'], true)) {
            if ($systemd) {
                $content = $this->systemd->render($process, $target, $target->certificateScope === null ? null : $this->accounts->resolve($target->node));
                $input += ['unit' => $name, 'content' => ['$secret' => 'process.unit_content', 'id' => $process->id],
                    'content_hash' => hash('sha256', $content), 'activation_pending' => $process->systemd_activation_pending];
            } else {
                $definition = $this->docker->containerInput($process, $target);
                $definition['environment'] = ['$secret' => 'process.environment', 'id' => $process->id];
                $definition['command'] = ['$secret' => 'process.command', 'id' => $process->id];
                $input += ['container' => $name, 'spec' => $this->docker->specHash($process, $target), ...$definition];
            }
            if ($process->retired_runtime !== null) {
                $retired = clone $process;
                $retired->runtime = $process->retired_runtime;
                $input += ['retired_runtime' => $retired->runtime->value,
                    'retired_name' => $retired->runtime === ProcessRuntime::Systemd ? $this->systemd->unitName($retired) : $this->docker->containerName($retired)];
            }
        }

        if ($process->isVpDev() && in_array($verb, ['create', 'update', 'start', 'restart'], true) && $target->instance !== null) {
            $instance = $target->instance;
            $marker = RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id));
            $input['vite_plan'] = [
                'suspend' => ['operation' => 'suspend', 'marker' => $marker],
                'environment' => ['operation' => 'environment', 'application' => $instance->source_is_laravel === true ? $instance->applicationDirectory() : $instance->checkout_path,
                    'path' => SystemdProcessRenderer::viteEnvironmentPath($instance->id), 'instance_id' => $instance->id, 'marker' => $marker,
                    'content' => "# Orbit Instance {$instance->id}\nORBIT_DEV_SERVER_PORT={$instance->vite_port}\n"],
                'awake' => ['operation' => 'awake', 'marker' => $marker],
                'listener' => ['unit' => $this->systemd->unitName($process), 'port' => $instance->vite_port, 'process_id' => $process->id, 'probe' => true],
            ];
        }

        return $input;
    }
}
