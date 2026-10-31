<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessPresets;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Process;
use SensitiveParameter;

/** Records definitions within the caller's transaction; the parent owns execution. */
final readonly class RecordProcessIntentAction
{
    public function __construct(private ProcessSpecification $specifications) {}

    /** @return array{process: Process, created: bool} */
    public function execute(#[SensitiveParameter] AddProcessData $data, ProcessTarget $target): array
    {
        $admission = $this->persist($this->prepare($data, $target));

        return ['process' => $admission['process'], 'created' => $admission['created']];
    }

    /** @return array{process: Process, created: bool, attributes: array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool}} */
    public function prepare(#[SensitiveParameter] AddProcessData $data, ProcessTarget $target): array
    {
        $owner = $data->targetType === ProcessTargetType::Node ? $target->node : $target->instance;
        if ($owner === null || $owner->id !== $data->targetId
            || ($data->targetType === ProcessTargetType::Node && $target->instance !== null)) {
            throw new ResourceOperationException('process.target_unavailable', 'The Process target differs from its admitted owner.', 409);
        }
        $this->specifications->assertOptions($data);
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

        return ['process' => $process, 'created' => $created, 'attributes' => $attributes];
    }

    /**
     * @param  array{process: Process, created: bool, attributes: array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool}}  $admission
     * @return array{process: Process, created: bool, attributes: array{runtime: ProcessRuntime, working_directory: ?string, runtime_config: array<string, mixed>, restart_policy: string, keep_alive: bool, restart_on_deploy: bool}}
     */
    public function persist(#[SensitiveParameter] array $admission): array
    {
        $process = $admission['process'];
        if ($admission['created']) {
            $process->fill([
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();
        }

        return $admission;
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
