<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampSchedulePublisher;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The proof reaper (F11): publishes the open Project 62 proof groups and their pinned VM targets as the
 * `proof-reaper` schedule's inputs, and cancels the groups that Swamp chose with the normal cancel intent.
 */
final readonly class ProofReaper implements ScheduledResultProjection
{
    public const string Workflow = 'c18de5dc-de46-401f-a452-a3d3096c3613';

    public const string StateKey = 'swamp.proof-reaper';

    /** Every ten minutes, at minute 7, 17 and so on, off the :00 and :30 marks. */
    public const string Schedule = '0 7-59/10 * * * *';

    /** Project `swamp-proof`; the reaper never reads or cancels another Project's groups. */
    public const int ProjectId = 62;

    public function activate(): void
    {
        if (app(TaskExtensionState::class)->enabled()) {
            $this->publish();
        }
    }

    /** Republish after a committed change; an unchanged snapshot sends nothing. */
    public function invalidate(): void
    {
        DB::afterCommit(function (): void {
            try {
                $this->publish();
            } catch (Throwable $exception) {
                Log::warning('Proof reaper schedule input publication was not confirmed.', ['exception_type' => $exception::class]);
            }
        });
    }

    public function publish(): void
    {
        if (! app(TaskExtensionState::class)->enabled()) {
            return;
        }
        $failure = DB::transaction(function (): ?Throwable {
            $record = $this->state();
            $state = $this->decode($record);
            $snapshot = $this->capture();
            if (($state['snapshot'] ?? null) === $snapshot && ($state['published'] ?? false) && ($state['schedule'] ?? null) === self::Schedule) {
                return null;
            }
            $revision = ValidatedData::integer($state['revision'] ?? 0) + 1;
            $inputs = ['revision' => $revision, ...$snapshot];
            $next = [...$state, 'revision' => $revision, 'snapshot' => $snapshot, 'published' => false];
            try {
                app(SwampSchedulePublisher::class)->publish(self::Workflow, self::Schedule, $inputs);
            } catch (Throwable $exception) {
                $record->value = json_encode($next, JSON_THROW_ON_ERROR);
                $record->saveQuietly();

                return $exception;
            }
            $record->value = json_encode([...$next, 'schedule' => self::Schedule, 'published' => true], JSON_THROW_ON_ERROR);
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
            throw new LogicException('Unknown proof reaper schedule.');
        }
        $runId = Arr::string($run, 'id');
        $fresh = DB::transaction(function () use ($run, $runId): bool {
            $record = $this->state();
            $state = $this->decode($record);
            $previous = ValidatedData::object($state['terminal'] ?? []);
            // Durable replay marker: a replayed or older run cancels nothing.
            if (($previous['run_id'] ?? null) === $runId || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo(Carbon::parse(Arr::string($run, 'startedAt'))))) {
                return false;
            }
            $record->value = json_encode([...$state, 'terminal' => ['run_id' => $runId, 'started_at' => $run['startedAt']]], JSON_THROW_ON_ERROR);
            $record->saveQuietly();

            return true;
        });
        if ($fresh && app(TaskExtensionState::class)->enabled() && ($run['status'] ?? null) === 'succeeded') {
            $this->cancel($runId, ValidatedData::object($run['inputs'] ?? []), $result);
        }
        $this->invalidate();
    }

    /**
     * @param  array<string,mixed>  $inputs
     * @param  array<string,mixed>  $result
     */
    private function cancel(string $runId, array $inputs, array $result): void
    {
        if (($result['revision'] ?? null) !== ($inputs['revision'] ?? null) || ! is_array($result['cancel'] ?? null)) {
            throw new ResourceOperationException('operation.result_invalid', 'Invalid proof reaper decision.', 422);
        }
        $published = array_column(array_filter(Arr::array($inputs, 'groups', []), is_array(...)), 'id');
        foreach (array_slice($result['cancel'], 0, 256) as $choice) {
            $id = is_array($choice) ? ($choice['group_id'] ?? null) : null;
            if (! is_int($id) || ! in_array($id, $published, true)) {
                continue;
            }
            $group = $this->open()->find($id);
            if (! $group instanceof Task) {
                continue;
            }
            // One admission per group and run: a replay returns it, and a later run retries a cancel that ended badly.
            $identity = app(OperationIdempotency::class);
            $prior = [$identity->kind, $identity->key, $identity->fingerprint];
            [$identity->kind, $identity->key, $identity->fingerprint] = ['tasks.cancel', 'proof-reaper:'.$runId.':g'.$id, hash('sha256', $runId.':'.$id)];
            try {
                app(TaskCancelIntent::class)->start($group, 'swamp:proof-reaper', null);
            } catch (ResourceOperationException $exception) {
                Log::warning('Proof reaper could not cancel a proof group.', ['group_id' => $id, 'error_code' => $exception->errorCode]);
            } finally {
                [$identity->kind, $identity->key, $identity->fingerprint] = $prior;
            }
        }
    }

    /** @return Builder<Task> */
    private function open(): Builder
    {
        return Task::topLevel()->where('project_id', self::ProjectId)->whereNotIn('status', array_map(
            static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()));
    }

    /** @return array{groups:list<array<string,mixed>>,targets:list<array<string,mixed>>} */
    private function capture(): array
    {
        $groups = [];
        $targets = [];
        foreach ($this->open()->orderBy('id')->get(['id', 'title', 'started_at']) as $group) {
            $vm = TaskVm::forGroup($group);
            $node = $vm?->state === TaskVmState::Ready ? $vm->node : null;
            $ssh = null;
            if ($node instanceof Node) {
                try {
                    $ssh = app(SwampSshExecutionInput::class)->forNode($node);
                } catch (ResourceOperationException) {
                    // Without a pinned host key the VM's heartbeat stays unread; only supersession applies.
                }
            }
            $groups[] = ['id' => $group->id, 'title' => $group->title, 'started_at' => $group->started_at?->getTimestamp(), 'running' => $ssh !== null];
            if ($ssh !== null) {
                $targets[] = ['group_id' => $group->id, 'node_id' => $node->id, ...$ssh['target'], 'identity_file' => $ssh['identity_file']];
            }
        }

        return ['groups' => $groups, 'targets' => $targets];
    }

    private function state(): Setting
    {
        return Setting::withoutEvents(fn (): Setting => Setting::query()->where('scope_type', SettingScopeType::Gateway)
            ->where('scope_id', 0)->where('key', self::StateKey)->lockForUpdate()->firstOrCreate([
                'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
            ], ['value' => '{}', 'is_secret' => false]));
    }

    /** @return array<string,mixed> */
    private function decode(Setting $state): array
    {
        return ValidatedData::object(json_decode($state->value ?? '{}', true, 512, JSON_THROW_ON_ERROR));
    }
}
