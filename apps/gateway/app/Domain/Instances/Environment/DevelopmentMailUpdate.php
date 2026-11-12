<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\ContainerProcessCommands;
use App\Domain\Instances\DevelopmentMail;
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
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
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
            $endpoint = app(DevelopmentMail::class)->forInstance($instance);
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
            $container = app(InstanceContainer::class)->intent($instance, $runtime);
            $units = $container['web_config'] === null ? [] : [InstanceContainer::FrankenPhpUnit];
            foreach ($instance->processes()->where('runtime', 'systemd')->where('status', 'active')->where('desired_state', 'running')->get() as $process) {
                $command = $process->runtime_config['command'] ?? [];
                $index = is_array($command) && array_is_list($command) && array_all($command, static fn (mixed $argument): bool => is_string($argument))
                    ? app(ContainerProcessCommands::class)->commandIndex($command) : null;
                if ($index !== null && preg_match('/^php(?:[0-9]+[.][0-9]+)?$/', basename($command[$index])) === 1
                    && basename($command[$index + 1] ?? '') === 'artisan'
                    && in_array($command[$index + 2] ?? null, ['queue:work', 'queue:listen', 'horizon'], true)) {
                    $units[] = app(SystemdProcessRenderer::class)->unitName($process);
                }
            }
            $refresh = ['php' => $container['php'], 'application' => InstanceContainer::application($instance),
                'units' => array_values(array_unique($units))];

            return app(StartOperationAction::class)->execute(self::Kind, 'instance', $instance->id, $instance->node_id,
                ['container' => $container, 'mail_refresh' => $refresh], $caller, $requestId,
                lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }

    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with('node')->findOrFail($operation->target_id);

        return ['intent' => [...$operation->input,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node)]];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        return app(DirectOperation::class)->project($operation,
            [...$data, 'error_code' => $result['error_code'] ?? $data['error_code'] ?? null], $result);
    }
}
