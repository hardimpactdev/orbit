<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessPresets;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SshProcessUserResolver;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class CreateProcessAction
{
    private ProcessRuntimeLease $lease;

    private ProcessSpecification $specifications;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessRuntimeLease $lease = null,
        ?ProcessSpecification $specifications = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->specifications = $specifications ?? new ProcessSpecification;
    }

    /** @return array{process: Process, created: bool} */
    public function execute(#[SensitiveParameter] AddProcessData $data): array
    {
        $operation = $this->admit($data);
        $operation = app(AwaitOperation::class)->execute($operation);
        if ($operation->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($operation->error_code ?? 'operation.failed', 'The runtime Operation did not succeed.', 409);
        }

        return ['process' => Process::query()->whereKey($operation->input['process_id'])->firstOrFail(), 'created' => $operation->input['created'] === true];
    }

    public function admit(#[SensitiveParameter] AddProcessData $data, ?Node $caller = null, ?string $requestId = null): Operation
    {
        if ($data->user !== null && ($data->targetType !== ProcessTargetType::Node || $data->runtime !== ProcessRuntime::Systemd || $data->preset !== null)) {
            throw new ResourceOperationException('process.option_invalid', 'The user option requires a Node systemd Process without a preset.');
        }
        $this->targets->resolve($data->targetType, $data->targetId);
        $ownerIds = $data->targetType === ProcessTargetType::Instance ? [$data->targetId] : [];

        return $this->admissions->run($ownerIds, fn (): Operation => DB::transaction(function () use ($data, $caller, $requestId): Operation {
            $admission = $this->reserveAdmission($data);

            return $this->lease->run($admission['process'], function (Process $fresh) use ($admission, $caller, $requestId): Operation {
                if (! $admission['created'] && ! $this->specifications->matches($fresh, $admission['attributes'])) {
                    throw new ResourceOperationException('process.name_taken', "Process [{$fresh->name}] already exists with different configuration.", 409);
                }
                $fresh->fill($admission['attributes'])->save();

                return app(ProcessOperationAdmission::class)->execute($fresh, 'create', $admission['created'], caller: $caller, requestId: $requestId);
            });
        }));
    }

    /**
     * @return array{process: Process, created: bool, attributes: array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool}}
     */
    private function reserveAdmission(#[SensitiveParameter] AddProcessData $data): array
    {
        $target = match ($data->targetType) {
            ProcessTargetType::Instance => $this->targets->forAdmission(
                Instance::query()
                    ->with('node')
                    ->lockForUpdate()
                    ->findOrFail($data->targetId),
            ),
            ProcessTargetType::Node => $this->targets->forNodeAdmission(
                Node::query()
                    ->lockForUpdate()
                    ->findOrFail($data->targetId),
            ),
        };
        if ($data->user !== null) {
            $target = new ProcessTarget(
                node: $target->node,
                user: $data->user,
                checkoutPath: app(SshProcessUserResolver::class)->home($target->node, $data->user),
            );
        }
        if ($data->preset !== null) {
            $this->assertPresetAdmission($data, $target);
        }
        $attributes = $this->specifications->attributes($data, $target);
        $process = Process::query()
            ->whereIn('owner_type', $data->targetType->storedTypes())
            ->where('owner_id', $data->targetId)
            ->where('name', $data->name)
            ->first() ?? new Process([
                'owner_type' => $data->targetType->storedType(),
                'owner_id' => $data->targetId,
                'name' => $data->name,
            ]);
        $created = ! $process->exists;
        $desiredState = $process->desired_state;

        if ($created) {
            $desiredState = $data->start ? DesiredProcessState::Running : DesiredProcessState::Stopped;
        }

        if ($process->exists && ! $this->specifications->matches($process, $attributes)) {
            throw new ResourceOperationException(
                errorCode: 'process.name_taken',
                message: "Process [{$data->name}] already exists with different configuration.",
                status: 409,
            );
        }

        $process->fill([
            ...$attributes,
            'desired_state' => $desiredState,
        ]);

        $this->runtime->assertCanStart($process);

        if ($created) {
            $process->fill([
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();
        }

        return ['process' => $process, 'created' => $created, 'attributes' => $attributes];
    }

    private function assertPresetAdmission(AddProcessData $data, ProcessTarget $target): void
    {
        if ($data->preset === null || ! ProcessPresets::isKnown($data->preset)) {
            throw new ResourceOperationException('process.preset_target_invalid', 'The Process preset is not supported.', 422);
        }

        if (! $target->instance?->placedOnAppDev()) {
            throw new ResourceOperationException(
                errorCode: 'process.preset_target_invalid',
                message: "The {$data->preset} preset requires a development Instance.",
                status: 422,
            );
        }

        $siblings = Process::query()
            ->whereIn('owner_type', Instance::morphTypes())
            ->where('owner_id', $data->targetId)
            ->where('name', '!=', $data->name)
            ->get();

        $duplicate = $siblings->contains(fn (Process $process): bool => ($process->runtime_config['preset'] ?? null) === $data->preset);

        if ($duplicate) {
            throw new ResourceOperationException(
                errorCode: 'process.preset_exists',
                message: "This Instance already has a {$data->preset} Process.",
                status: 409,
            );
        }
    }
}
