<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Admission and terminal projection for the narrow current-file update, never a general environment sync. */
final readonly class DevelopmentMailUpdate implements DirectOperationProjection
{
    public const string Kind = 'instance.mail_update';

    public function start(Instance $instance, ?string $caller, ?string $requestId): Operation
    {
        if (($replay = app(OperationIdempotency::class)->replay(self::Kind)) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($instance, $caller, $requestId): Operation {
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($instance->id);
            $runtime = $instance->runtime()->first();
            if ($runtime === null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Mail update requires a container Instance.', 409);
            }
            $endpoint = app(DevelopmentMailEnvironment::class)->endpoint($instance->node);
            if ($instance->placementEnvironment() !== 'development' || $endpoint === null) {
                throw new ResourceOperationException('instance.host_unavailable', 'This placement has no development Mailpit endpoint.', 409);
            }
            if ($instance->status !== InstanceState::Active
                || Operation::query()->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))
                    ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance has unfinished lifecycle work.', 409);
            }
            if ($runtime->power_state !== InstanceRuntimePowerState::Running) {
                throw new ResourceOperationException('process.target_inactive', 'The container is stopped; mail update does not start it.', 409);
            }
            $units = [];
            foreach ($instance->processes()->where('status', 'active')->where('desired_state', 'running')->get() as $process) {
                if (! $process->isVpDev()) {
                    $units[] = app(SystemdProcessRenderer::class)->unitName($process);
                }
            }
            foreach (app(InstanceContainer::class)->units($instance, $runtime, InstanceContainer::application($instance), null) as $unit) {
                $units[] = $unit['name'];
            }
            $mail = ['project' => $runtime->incus_project, 'name' => $runtime->container_name,
                'backup' => '/var/lib/orbit/instance-mail/environment.backup',
                'instance_id' => $instance->id, 'application' => InstanceContainer::application($instance),
                'endpoint' => $endpoint, 'owned' => $instance->environment_owned_keys ?? [],
                'laravel' => $instance->project->is_laravel === true, 'units' => array_values(array_unique($units))];

            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id,
                ['mail' => $mail], $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);
        // Deliver the very same visible PHP rule as an ordinary execution copy, never a second policy implementation.
        $source = file_get_contents(__DIR__.'/DevelopmentMailEnvironment.php');

        return ['intent' => ['mail' => $operation->input['mail'],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)],
            'execution_secrets' => ['resolver' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs',
                'instance-mail-resolver-'.$operation->id, $source === false ? throw new \LogicException('Mail rule is missing.') : $source)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            if (! $operation->refresh()->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                $instance = Instance::query()->lockForUpdate()->findOrFail($operation->target_id);
                $keys = ValidatedData::stringList($result['mail_keys_taken_over'] ?? []);
                $instance->forceFill(['environment_owned_keys' => array_values(array_unique([
                    ...($instance->environment_owned_keys ?? []), ...$keys]))])->saveOrFail();
            }

            return app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null], $result);
        });
    }
}
