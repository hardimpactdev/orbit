<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Shared\LifecycleStatus;
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
    public const string AccountHomeToken = '/__orbit_process_account_home__';

    public function __construct(private StartOperationAction $operations, private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd, private DockerProcessRenderer $docker) {}

    public function execute(Process $process, string $verb, bool $created = false, bool $preserveRecord = false, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($process, $verb, $created, $preserveRecord, $caller, $requestId): Operation {
            $input = $this->inputFor($process, $verb, $created, $preserveRecord, $caller);
            if (in_array($verb, ['create', 'update'], true) && $process->runtime === ProcessRuntime::Systemd) {
                $input['activation_pending'] = $process->systemd_activation_pending || $process->systemd_activation_hash !== $input['content_hash'];
            }
            $nodeId = ValidatedData::integer($input['node_id']);
            if (! in_array($verb, ['create', 'update', 'destroy'], true)) {
                $input['agent_sha256'] = app(AgentDelivery::class)->pin(Node::query()->findOrFail($nodeId));
            }
            $operation = $this->operations->execute('process.'.$verb, 'process', $process->id, $nodeId, $input, 'operator', $requestId);
            $process->update(['status' => $verb === 'destroy' ? LifecycleStatus::Removing : LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);

            return $operation;
        });
    }

    /** @return array<string, mixed> */
    public function inputFor(Process $process, string $verb, bool $created = false, bool $preserveRecord = false, ?Node $caller = null, ?ProcessTarget $target = null): array
    {
        $providedTarget = $target;
        $target ??= match ($verb) {
            'destroy' => $this->targets->forRemoval($process),
            'start', 'restart' => $this->targets->forStart($process),
            default => $this->targets->forInstallation($process),
        };
        if ($process->isVpDev() && in_array($verb, ['create', 'update', 'start', 'restart'], true)) {
            $instance = $target->instance;
            if ($instance !== null) {
                app(VitePortAllocator::class)->assign($instance, recheck: true);
                $target = $providedTarget ?? $this->targets->forInstallation($process);
            }
        }

        return $this->inputForTarget($process, $verb, $target, created: $created, preserveRecord: $preserveRecord, caller: $caller);
    }

    /** DB-only description of a supplied admitted placement; no account or port discovery.
     * @return array<string,mixed>
     */
    public function inputForTarget(Process $process, string $verb, ProcessTarget $target, ?ManagedUserAccount $account = null, bool $created = false, bool $preserveRecord = false, ?Node $caller = null): array
    {
        $systemd = $process->runtime === ProcessRuntime::Systemd;
        $templateAccount = $account === null && $systemd && ! $process->isVpDev() && $target->certificateScope !== null;
        if ($templateAccount) {
            $account = new ManagedUserAccount($target->user, '__orbit_process_account_group__', self::AccountHomeToken);
        }
        $name = $systemd ? $this->systemd->unitName($process) : $this->docker->containerName($process);
        $input = ['process_id' => $process->id, 'node_id' => $target->node->id, 'name' => $name,
            'runtime' => $process->runtime->value, 'variant' => $process->isVpDev() && in_array($verb, ['create', 'update', 'start', 'restart'], true) ? 'vite' : $process->runtime->value, 'desired' => $process->desired_state->value,
            'on_demand' => $target->onDemandHostStart, 'created' => $created, 'preserve_record' => $preserveRecord,
            'caller_node_id' => $caller?->id,
            'vite' => $verb !== 'stop' && $systemd && $process->isVpDev() ? ['path' => SystemdProcessRenderer::viteEnvironmentPath($process->owner_id), 'instance_id' => $process->owner_id] : null];
        if ($systemd && in_array($verb, ['create', 'update'], true)) {
            $input['required_mount_paths'] = RequiredMountPaths::parse($process->runtime_config['required_mount_paths'] ?? []);
        }
        if ($target->productionReleaseLayout && ($process->desired_state === DesiredProcessState::Running || in_array($verb, ['start', 'restart'], true)) && $verb !== 'destroy' && $verb !== 'stop') {
            $input['directory'] = $target->defaultWorkingDirectory;
        }
        if (in_array($verb, ['create', 'update'], true)) {
            if ($systemd) {
                $content = $this->systemd->render($process, $target, $account);
                if ($templateAccount) {
                    $input['account_user'] = $target->user;
                }
                $input += ['content_hash' => hash('sha256', $content), 'activation_pending' => $process->systemd_activation_pending];
            } else {
                $input['spec'] = $this->docker->specHash($process, $target);
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
