<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Swamp\SwampSchedulePublisher;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The image builder reaper: publishes each active task VM Incus host, the build ids of its active image builds and
 * its pinned SSH target as the `task-vm-image-reaper` schedule's inputs. Swamp deletes the old unreferenced builders;
 * the run records them, so a terminal only republishes.
 */
final readonly class ImageReaper implements ScheduledResultProjection
{
    public const string Workflow = '39da727f-537c-4ff8-8955-ae713f91b20d';

    public const string StateKey = 'swamp.task-vm-image-reaper';

    /** Every thirty minutes, the reaper's cadence before it was unscheduled. */
    public const string Schedule = '0 */30 * * * *';

    public function activate(): void
    {
        $this->publish();
    }

    /** Republish after a committed change; an unchanged snapshot sends nothing. */
    public function invalidate(): void
    {
        DB::afterCommit(function (): void {
            try {
                $this->publish();
            } catch (Throwable $exception) {
                Log::warning('Image reaper schedule input publication was not confirmed.', ['exception_type' => $exception::class]);
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
            $state = ValidatedData::object(json_decode($record->value ?? '{}', true, 512, JSON_THROW_ON_ERROR));
            $hosts = $this->capture();
            if (($state['hosts'] ?? null) === $hosts && ($state['published'] ?? false) && ($state['schedule'] ?? null) === self::Schedule) {
                return null;
            }
            $revision = ValidatedData::integer($state['revision'] ?? 0) + 1;
            $next = ['revision' => $revision, 'hosts' => $hosts, 'published' => false];
            try {
                app(SwampSchedulePublisher::class)->publish(self::Workflow, self::Schedule, ['revision' => $revision, 'hosts' => $hosts]);
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
            throw new LogicException('Unknown image reaper schedule.');
        }
        $this->invalidate();
    }

    /** @return list<array<string,mixed>> */
    private function capture(): array
    {
        $hosts = [];
        foreach (app(TaskVmSettings::class)->hosts as $host) {
            $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first();
            if (! $node instanceof Node) {
                continue;
            }
            try {
                $ssh = app(SwampSshExecutionInput::class)->forNode($node);
            } catch (ResourceOperationException) {
                // Without a pinned host key the host stays unread until it has one.
                continue;
            }
            $builds = Operation::query()->where('kind', 'task_vm.image_build')
                ->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Running->value, OperationStatus::Waiting->value])
                ->where('input->host_node_id', $node->id)->get(['input'])
                ->map(static fn (Operation $operation): mixed => $operation->input['build_id'] ?? null)->filter(static fn (mixed $build): bool => is_int($build))->unique()->sort()->values()->all();
            $hosts[] = ['node_id' => $node->id, 'project' => $host->project, 'pool' => $host->pool, 'operator_owned' => $host->operatorOwned,
                'protected_builds' => $builds, ...$ssh['target'], 'identity_file' => $ssh['identity_file']];
        }

        return $hosts;
    }

    private function state(): Setting
    {
        return Setting::withoutEvents(fn (): Setting => Setting::query()->where('scope_type', SettingScopeType::Gateway)
            ->where('scope_id', 0)->where('key', self::StateKey)->lockForUpdate()->firstOrCreate([
                'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
            ], ['value' => '{}', 'is_secret' => false]));
    }
}
