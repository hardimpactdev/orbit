<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Swamp\SwampSchedulePublisher;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Decision 6 producer: native selection followed by normal validated cleanup admission. */
final readonly class ScheduledTaskVmReaper implements ScheduledResultProjection
{
    public const string Workflow = '97255f74-03e4-4814-aef6-f61888786763';

    public const string StateKey = 'swamp.task-vm-reaper';

    public function activate(): void
    {
        $this->publish();
    }

    public function invalidate(): void
    {
        if ($this->query()->exists()) {
            DB::afterCommit(fn () => $this->notify());
        }
    }

    /** Publish only a changed full expectation set, not every Task or VM commit. */
    public function publish(): void
    {
        if (! app(TaskExtensionState::class)->enabled()) {
            return;
        }
        $failure = DB::transaction(function (): ?Throwable {
            $record = $this->state();
            $state = $this->decode($record);
            $snapshot = $this->capture();
            $inputs = ['expectations_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'expectations' => $snapshot];
            if (($state['published'] ?? false) && ($state['inputs'] ?? null) === $inputs) {
                return null;
            }
            $live = array_fill_keys(array_map(static fn (array $vm): string => 'vm-'.$vm['task_vm_id'], $snapshot['vms']), true);
            $admissions = array_intersect_key(ValidatedData::object($state['admissions'] ?? []), $live);
            $next = [...$state, 'inputs' => $inputs, 'admissions' => $admissions, 'published' => false];
            try {
                app(SwampSchedulePublisher::class)->publish(self::Workflow, '20 * * * * *', $inputs);
            } catch (Throwable $exception) {
                $record->value = json_encode($next, JSON_THROW_ON_ERROR);
                $record->saveQuietly();

                return $exception;
            }
            $record->value = json_encode([...$next, 'published' => true], JSON_THROW_ON_ERROR);
            $record->saveQuietly();

            return null;
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new \LogicException('Unknown Task VM schedule.');
        }
        DB::transaction(function () use ($run, $result): void {
            $record = $this->state();
            $state = $this->decode($record);
            $previous = ValidatedData::object($state['terminal'] ?? []);
            if (($previous['run_id'] ?? null) === $run['id'] || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo(Carbon::parse(Arr::string($run, 'startedAt'))))) {
                return;
            }
            $inputs = ValidatedData::object($run['inputs'] ?? []);
            $snapshot = $this->capture();
            $usable = app(TaskExtensionState::class)->enabled() && ($run['status'] ?? null) === 'succeeded'
                && ($state['published'] ?? false) && $inputs === ($state['inputs'] ?? null)
                && ($result['expectations_hash'] ?? null) === ($inputs['expectations_hash'] ?? null)
                && ($inputs['expectations_hash'] ?? null) === hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $admissions = ValidatedData::object($state['admissions'] ?? []);
            $outcomes = [];
            if ($usable) {
                foreach (array_map(ValidatedData::object(...), Arr::array($result, 'requests', [])) as $request) {
                    $vmId = ValidatedData::integer($request['task_vm_id'] ?? null);
                    $expected = collect($snapshot['vms'])->firstWhere('task_vm_id', $vmId);
                    $reason = $request['reason'] ?? null;
                    unset($request['reason']);
                    if ($expected !== $request || ! in_array($reason, ['proof_age', 'no_live_group', 'assistance_unanswered', 'no_progress'], true)
                        || ($expected['claimed_at'] ?? null) === null) {
                        throw new ResourceOperationException('operation.result_invalid', 'Invalid native Task VM cleanup selection.', 422);
                    }
                    $key = hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR));
                    $vmKey = 'vm-'.$vmId;
                    $priorAdmission = ValidatedData::object($admissions[$vmKey] ?? []);
                    $priorOperation = Operation::query()->find($priorAdmission['operation_id'] ?? 0);
                    if (($priorAdmission['key'] ?? null) === $key && $priorOperation !== null
                        && (! $priorOperation->status->terminal() || $priorOperation->status === OperationStatus::Succeeded)) {
                        continue;
                    }
                    $vm = TaskVm::query()->live()->lockForUpdate()->find($vmId);
                    $group = $vm?->group_id === null ? null : Task::topLevel()->lockForUpdate()->find($vm->group_id);
                    if ($vm === null || app(TaskVmReaperInput::class)->vm($vm) !== $expected) {
                        continue;
                    }
                    $identity = app(OperationIdempotency::class);
                    $prior = [$identity->kind, $identity->key, $identity->fingerprint];
                    $identity->kind = $group === null ? 'task_vm.destroy' : 'tasks.cancel';
                    $identity->key = 'native-task-vm-reaper:'.$vmId.':'.Arr::string($run, 'id');
                    $identity->fingerprint = $key;
                    try {
                        $operation = $group === null
                            ? app(StartTaskVmDestroyAction::class)->execute($vmId)
                            : app(TaskCancelIntent::class)->start($group, 'task-vm-reaper', null);
                        if ($operation !== null) {
                            $admissions[$vmKey] = ['key' => $key, 'operation_id' => $operation->id];
                            $outcomes[$vmId] = 'admitted';
                        }
                    } catch (ResourceOperationException $exception) {
                        $outcomes[$vmId] = $exception->errorCode;
                    } finally {
                        [$identity->kind, $identity->key, $identity->fingerprint] = $prior;
                    }
                }
            }
            $record->value = json_encode([...$state, 'admissions' => $admissions,
                'terminal' => ['run_id' => $run['id'], 'started_at' => $run['startedAt'], 'status' => $usable ? 'recorded' : 'stale', 'outcomes' => $outcomes]], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
            DB::afterCommit(fn () => $this->notify());
        });
    }

    private function notify(): void
    {
        try {
            $this->publish();
        } catch (Throwable $exception) {
            Log::warning('Native Task VM schedule input publication was not confirmed.', ['exception_type' => $exception::class]);
        }
    }

    /** @return array{proof_project_id:int|null,vms:list<array<string,mixed>>} */
    private function capture(): array
    {
        return ['proof_project_id' => Project::query()->where('slug', 'swamp-proof')->first()?->id, ...app(TaskVmReaperInput::class)->capture()];
    }

    /** @return Builder<Setting> */
    private function query(): Builder
    {
        return Setting::query()->where('scope_type', SettingScopeType::Gateway)->where('scope_id', 0)->where('key', self::StateKey);
    }

    private function state(): Setting
    {
        return Setting::withoutEvents(fn (): Setting => $this->query()->lockForUpdate()->firstOrCreate([
            'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
        ], ['value' => '{}', 'is_secret' => false]));
    }

    /** @return array<string,mixed> */
    private function decode(Setting $state): array
    {
        return ValidatedData::object(json_decode($state->value ?? '{}', true, 512, JSON_THROW_ON_ERROR));
    }
}
