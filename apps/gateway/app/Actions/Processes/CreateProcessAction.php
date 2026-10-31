<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Data\Processes\AddProcessData;
use App\Data\Processes\ProcessData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessOperationException;
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

    private RecordEventBroadcaster $broadcaster;

    public function __construct(
        private ProcessTargetResolver $targets,
        private ProcessRuntimeManager $runtime,
        private ProcessAdmissionLock $admissions,
        ?ProcessRuntimeLease $lease = null,
        ?ProcessSpecification $specifications = null,
        ?RecordEventBroadcaster $broadcaster = null,
    ) {
        $this->lease = $lease ?? app(ProcessRuntimeLease::class);
        $this->specifications = $specifications ?? new ProcessSpecification;
        $this->broadcaster = $broadcaster ?? app(RecordEventBroadcaster::class);
    }

    /** @return array{process: Process, created: bool} */
    public function execute(#[SensitiveParameter] AddProcessData $data): array
    {
        $this->specifications->assertOptions($data);
        $this->targets->resolve($data->targetType, $data->targetId);
        $ownerIds = $data->targetType === ProcessTargetType::Instance ? [$data->targetId] : [];

        return $this->admissions->run(
            $ownerIds,
            fn (): array => $this->executeOwned($data),
        );
    }

    /** @return array{process: Process, created: bool} */
    private function executeOwned(#[SensitiveParameter] AddProcessData $data): array
    {
        $admission = DB::transaction(fn (): array => $this->reserveAdmission($data));

        return $this->lease->run($admission['process'], function (Process $fresh) use ($admission): array {
            if (! $admission['created'] && ! $this->specifications->matches($fresh, $admission['attributes'])) {
                throw new ResourceOperationException(
                    errorCode: 'process.name_taken',
                    message: "Process [{$fresh->name}] already exists with different configuration.",
                    status: 409,
                );
            }

            $fresh->fill([
                ...$admission['attributes'],
                'desired_state' => $fresh->desired_state,
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            try {
                $this->runtime->converge($fresh);
            } catch (ProcessOperationException $exception) {
                $fresh->update([
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => $exception->step,
                    'error_code' => $exception->errorCode,
                ]);

                throw $exception;
            }

            $fresh->update([
                'status' => LifecycleStatus::Active,
                'failed_step' => null,
                'error_code' => null,
            ]);

            $fresh = $fresh->refresh();

            if ($admission['created']) {
                $this->broadcaster->broadcast(
                    RecordEventType::ProcessCreated,
                    $fresh->id,
                    ProcessData::fromModel($fresh, $this->runtime->status($fresh))->toArray(),
                );
            }

            return ['process' => $fresh, 'created' => $admission['created']];
        });
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
        $records = app(RecordProcessIntentAction::class);
        $admission = $records->prepare($data, $target);
        $this->runtime->assertCanStart($admission['process']);

        return $records->persist($admission);
    }

    public function admit(#[SensitiveParameter] AddProcessData $data, ?Node $caller = null, ?string $requestId = null): Operation
    {
        $this->specifications->assertOptions($data);
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
}
