<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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

    /** `$namedInstanceId` is the Instance the caller named for a destroy; a container Process is removed only with its own. */
    public function execute(Process $process, string $verb, bool $created = false, bool $preserveRecord = false, ?Node $caller = null, ?string $requestId = null, ?int $namedInstanceId = null): Operation
    {
        return DB::transaction(function () use ($process, $verb, $created, $preserveRecord, $caller, $requestId, $namedInstanceId): Operation {
            if (in_array($verb, ['start', 'stop', 'restart'], true) && $process->status === LifecycleStatus::Removing
                && $this->targets->forInspection($process)->container !== null) {
                // Its definition is gone; the default's next deployment removes it (docs/reference/development-containers.md#processes).
                throw new ResourceOperationException('process.removing', "Process [{$process->name}] is being removed: the Project removed its definition.", 409);
            }
            $input = $this->inputFor($process, $verb, $created, $preserveRecord, $caller);
            if (isset($input['container']) && in_array($verb, ['create', 'update', 'destroy'], true)) {
                // A container Instance takes its Processes from the Project's development definitions, which a deployment installs.
                app(ContainerProcessChange::class)->assertAdmissible($process, $verb, $namedInstanceId);
            }
            if (in_array($verb, ['create', 'update'], true) && $process->runtime === ProcessRuntime::Systemd) {
                $input['activation_pending'] = $process->systemd_activation_pending || $process->systemd_activation_hash !== $input['content_hash'];
            }
            $nodeId = ValidatedData::integer($input['node_id']);
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
        if ($target->container === null && $process->isVpDev() && in_array($verb, ['create', 'update', 'start', 'restart'], true)) {
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
        $hostVite = $target->container === null && $process->isVpDev();
        $templateAccount = $account === null && $systemd && ! $process->isVpDev() && $target->certificateScope !== null;
        if ($templateAccount) {
            $account = new ManagedUserAccount($target->user, '__orbit_process_account_group__', self::AccountHomeToken);
        }
        $name = $systemd ? $this->systemd->unitName($process) : $this->docker->containerName($process);
        $input = ['process_id' => $process->id, 'node_id' => $target->node->id, 'name' => $name,
            'runtime' => $process->runtime->value, 'variant' => $hostVite && in_array($verb, ['create', 'update', 'start', 'restart'], true) ? 'vite' : $process->runtime->value, 'desired' => $process->desired_state->value,
            'on_demand' => $target->onDemandHostStart, 'created' => $created, 'preserve_record' => $preserveRecord,
            'caller_node_id' => $caller?->id,
            'vite' => $verb !== 'stop' && $systemd && $hostVite ? ['path' => SystemdProcessRenderer::viteEnvironmentPath($process->owner_id), 'instance_id' => $process->owner_id] : null];
        if ($target->container !== null) {
            // The unit lives in the Instance container; Swamp acts on it through `incus exec` on the Node.
            $input['container'] = $target->container;
        }
        if (! $systemd && in_array($verb, ['start', 'stop', 'restart'], true)) {
            $input['image'] = $this->docker->containerInput($process, $target)['image'];
        }
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

        if ($hostVite && in_array($verb, ['create', 'update', 'start', 'restart'], true) && $target->instance !== null) {
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
