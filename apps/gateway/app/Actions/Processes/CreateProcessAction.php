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
    public function bootstrapCollector(#[SensitiveParameter] AddProcessData $data): array
    {
        return $this->bootstrap($data, 'ai-router-collector', ProcessRuntime::Systemd, ['/usr/local/bin/orbit-agent', 'service', 'ai-router-collector']);
    }

    /** @return array{process: Process, created: bool} */
    public function bootstrapPlausible(#[SensitiveParameter] AddProcessData $data): array
    {
        return $this->bootstrap($data, 'plausible', ProcessRuntime::Docker, ['/usr/local/bin/orbit-agent', 'service', 'plausible']);
    }

    /** @return array{process: Process, created: bool} */
    public function bootstrapPi(#[SensitiveParameter] AddProcessData $data): array
    {
        if (array_slice($data->command, 1, 1) !== ['serve']) {
            throw new ResourceOperationException('process.bootstrap_invalid', 'Pi bootstrap requires its server command.', 409);
        }

        return $this->bootstrap($data, 'pi-server', ProcessRuntime::Systemd, $data->command);
    }

    /** @return array{process: Process, created: bool} */
    public function bootstrapHorizon(#[SensitiveParameter] AddProcessData $data): array
    {
        if (array_slice($data->command, 1) !== ['artisan', 'horizon']) {
            throw new ResourceOperationException('process.bootstrap_invalid', 'Horizon bootstrap requires its server command.', 409);
        }

        return $this->bootstrap($data, 'horizon', ProcessRuntime::Systemd, $data->command);
    }

    /**
     * Only the four fixed bootstrap callers may execute here until their completion transitions move native.
     *
     * @param  list<string>  $command
     * @return array{process: Process, created: bool}
     */
    private function bootstrap(#[SensitiveParameter] AddProcessData $data, string $name, ProcessRuntime $runtime, array $command): array
    {
        if ($data->targetType !== ProcessTargetType::Node || $data->name !== $name || $data->runtime !== $runtime
            || $data->command !== $command || $data->user !== null) {
            throw new ResourceOperationException('process.bootstrap_invalid', 'Bootstrap requires its fixed Node Process specification.', 409);
        }

        return $this->admissions->run([], function () use ($data): array {
            $this->specifications->assertOptions($data);
            $this->targets->resolve($data->targetType, $data->targetId);
            $admission = DB::transaction(fn (): array => $this->reserveAdmission($data));

            return $this->lease->run($admission['process'], function (Process $fresh) use ($admission): array {
                if (! $this->specifications->matches($fresh, $admission['attributes'])) {
                    throw new ResourceOperationException('process.name_taken', 'The bootstrap Process specification changed before execution.', 409);
                }
                $fresh->update(['status' => LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);
                try {
                    $this->runtime->converge($fresh);
                } catch (ProcessOperationException $exception) {
                    $fresh->update(['status' => LifecycleStatus::Failed, 'failed_step' => $exception->step, 'error_code' => $exception->errorCode]);
                    throw $exception;
                }
                $fresh->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
                $fresh->refresh();
                if ($admission['created']) {
                    $this->broadcaster->broadcast(RecordEventType::ProcessCreated, $fresh->id,
                        ProcessData::fromModel($fresh, $this->runtime->status($fresh))->toArray());
                }

                return ['process' => $fresh, 'created' => $admission['created']];
            });
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

        return DB::transaction(function () use ($data, $caller, $requestId): Operation {
            $admission = $this->reserveAdmission($data);
            $process = $admission['process'];
            $process->fill($admission['attributes'])->save();

            return app(ProcessOperationAdmission::class)->execute($process, 'create', $admission['created'], caller: $caller, requestId: $requestId);
        });
    }
}
