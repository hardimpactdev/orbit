<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Record-only Schedule definition capture; native steps own installation. */
final readonly class ScheduleInstanceOperation implements DirectOperationProjection
{
    public function __construct(private StartOperationAction $operations, private ScheduleOperationAdmission $admission,
        private ScheduleControlProjection $schedules, private ScheduleOperationCompletion $completion, private DirectOperation $direct) {}

    public function execute(Instance $instance, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($instance, $requestId): Operation {
            $instance = Instance::query()->with('node')->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $input = $this->capture($instance);

            return $this->operations->execute('schedule.install_instance_definitions', 'instance', $instance->id, $instance->node_id, $input, 'operator', $requestId);
        });
    }

    /** @return array<string,mixed> */
    public function capture(Instance $instance): array
    {
        if ($instance->placedOnAppProd()) {
            $instance = app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForClone($instance);
        }
        $records = Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instance->id)
            ->whereNotNull('source_definition_id')->whereNotIn('status', [LifecycleStatus::Active, LifecycleStatus::Removing])
            ->orderBy('id')->lockForUpdate()->limit(4097)->get();
        if ($records->count() > 4096) {
            throw new ResourceOperationException('operation.runtime_limit', 'An Instance runtime plan may contain at most 4096 members.', 409);
        }
        $members = [];
        foreach ($records as $record) {
            $input = $this->admission->inputFor($record, 'create');
            if ($input['node_id'] !== $instance->node_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule is placed on another Node.', 422);
            }
            unset($input['command']);
            $members[] = ['id' => $record->id, 'input' => $input];
        }

        return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'members' => $members];
    }

    /** @return list<array{id:string,input:array<string,mixed>}> */
    public static function members(Operation $operation): array
    {
        $members = $operation->input['members'];
        if (! is_array($members) || ! array_is_list($members)) {
            throw new \InvalidArgumentException('The admitted runtime members must be a list.');
        }
        $result = [];
        foreach ($members as $value) {
            $member = ValidatedData::object($value);
            $result[] = ['id' => ValidatedData::string($member['id']), 'input' => ValidatedData::object($member['input'])];
        }

        return $result;
    }

    /** @param array<string,mixed> $member */
    public static function member(Operation $parent, array $member): Operation
    {
        $view = new Operation;
        $view->forceFill(['id' => $parent->id, 'status' => $parent->status, 'kind' => 'schedule.create', 'target_type' => 'instance_schedules',
            'target_id' => $parent->target_id, 'node_id' => $parent->node_id, 'input' => $member['input']]);

        return $view;
    }

    public function input(Operation $operation): array
    {
        $members = [];
        foreach (self::members($operation) as $member) {
            $schedule = Schedule::query()->findOrFail($member['id']);
            if (! Instance::isMorphType($schedule->target_type) || $schedule->target_id !== $operation->target_id
                || $schedule->host_node_id !== $operation->node_id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The runtime member belongs to another owner.', 422);
            }
            $members[] = ['id' => $member['id'], 'intent' => $this->schedules->input(self::member($operation, $member))['intent']];
        }

        return ['intent' => ['instance_id' => $operation->target_id, 'members' => $members], 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $code = ValidatedData::nullableString($data['error_code'] ?? null);
            $result = $status === OperationStatus::Succeeded ? ['ready' => true] : ($code === null ? [] : ['error_code' => $code]);
            $projected = $this->direct->project($operation, $data, $result);
            if (! $terminal && $status->terminal()) {
                $outcome = match ($status) {
                    OperationStatus::Succeeded => OperationOutcome::succeeded($result),
                    OperationStatus::Failed => OperationOutcome::failed(ValidatedData::string($code), $result),
                    OperationStatus::Interrupted => OperationOutcome::interrupted($code, $result),
                    OperationStatus::Cancelled => OperationOutcome::cancelled(),
                    default => throw new \LogicException('A terminal Schedule parent status is required.'),
                };
                foreach (self::members($operation) as $member) {
                    $this->completion->complete(self::member($operation, $member), $outcome);
                }
            }

            return $projected;
        });
    }
}
