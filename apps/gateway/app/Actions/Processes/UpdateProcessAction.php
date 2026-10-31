<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Data\Processes\UpdateProcessData;
use App\Domain\DatabaseServers\DatabaseServerProcessOwnership;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SshProcessUserResolver;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;

final readonly class UpdateProcessAction
{
    public function __construct(
        private ProcessRuntimeManager $runtime,
        private ProcessTargetResolver $targets,
        private ProcessSpecification $specifications,
        private SshProcessUserResolver $users,
        private DatabaseServerProcessOwnership $databaseServers,
        private CustomProxyProcessListener $listeners,
    ) {}

    public function admit(Process $process, UpdateProcessData $update, ?Node $caller = null, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($process, $update, $caller, $requestId): Operation {
            $fresh = $process->refresh();
            $this->databaseServers->assertUpdatable($fresh);
            if (isset($fresh->runtime_config['preset'])) {
                throw new ResourceOperationException('process.option_invalid', 'Preset Processes cannot be updated.', 422);
            }
            $data = $update->forProcess($fresh);
            $target = $this->targets->forInstallation($fresh);
            if ($data->user !== null && $data->runtime === ProcessRuntime::Systemd) {
                $home = $this->users->home($target->node, $data->user);
                $data = new AddProcessData(
                    $data->targetType, $data->targetId, $data->name, $data->runtime,
                    $data->command, $data->image, $data->workingDirectory ?? $home,
                    $data->environment, $data->ports, $data->volumes, $data->restartPolicy,
                    false, $data->keepAlive, user: $data->user, restartOnDeploy: $data->restartOnDeploy,
                );
            }
            $attributes = $this->specifications->attributes($data, $target);
            $update->assertPreservesOmittedFields($fresh, $attributes['runtime_config']);
            $candidate = clone $fresh;
            $candidate->fill($attributes);
            $this->listeners->assertPreservesRoutes($candidate);
            $retiredRuntime = $fresh->runtime !== $attributes['runtime'] ? $fresh->runtime : $fresh->retired_runtime;
            $fresh->fill($attributes);
            if ($fresh->desired_state === DesiredProcessState::Running) {
                $this->runtime->assertCanStart($fresh);
            }
            $fresh->retired_runtime = $retiredRuntime;
            $fresh->fill(['status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null])->save();

            return app(ProcessOperationAdmission::class)->execute($fresh, 'update', caller: $caller, requestId: $requestId);
        });
    }
}
