<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPrModeChoice;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Where a task copy's group stands on its way to a pull request (docs/reference/tasks.md#publication-state). Read from records
 * alone; not a board status. A Gateway mode setting that is no mode leaves `mode` and `mode_source` null.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskPublicationData extends Data
{
    public const string Working = 'working';

    public const string WaitingForRelease = 'waiting_for_release';

    public const string Publishing = 'publishing';

    public const string Refused = 'refused';

    public const string Published = 'published';

    public function __construct(
        public ?TaskPrMode $mode,
        public ?string $modeSource,
        public string $state,
        public ?int $checkId = null,
        public ?string $commit = null,
        public ?int $operationId = null,
        public ?string $errorCode = null,
    ) {}

    /** Null for a group without a task copy. */
    public static function fromModel(Task $group): ?self
    {
        $group->loadMissing('project', 'taskable');
        if ($group->parent_id !== null || TaskInstanceWorkspace::forGroup($group) === null) {
            return null;
        }
        try {
            $choice = TaskPrModeChoice::of($group);
        } catch (ResourceOperationException) {
            $choice = null;
        }
        $mode = $choice?->mode;
        $source = $choice?->source;
        $publishes = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)->orderByDesc('id');
        if ($group->pr_url !== null) {
            $published = (clone $publishes)->where('status', OperationStatus::Succeeded->value)->first();

            return new self($mode, $source, self::Published, self::checkOf($published), self::commitOf($published), $published?->id);
        }
        $running = (clone $publishes)->whereNotIn('status', OperationStatus::terminalValues())->first();
        if ($running instanceof Operation) {
            return new self($mode, $source, self::Publishing, self::checkOf($running), self::commitOf($running), $running->id);
        }
        $check = app(TaskGroupDone::class)->check($group);
        if (! $check instanceof TaskCheck) {
            return new self($mode, $source, self::Working);
        }
        $refusal = $group->publish_refusal;
        if (is_array($refusal) && ($refusal['check_id'] ?? null) === $check->id) {
            return new self($mode, $source, self::Refused, $check->id, $check->commit_sha, null, is_string($refusal['code'] ?? null) ? $refusal['code'] : null);
        }
        $failed = (clone $publishes)->where('input->check', $check->id)->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Interrupted->value])->first();
        if ($failed instanceof Operation) {
            return new self($mode, $source, self::Refused, $check->id, $check->commit_sha, $failed->id, $failed->error_code);
        }
        // A pr_when_done group whose done check recorded pr_when_done is the tick's to publish, within a minute.
        $state = $mode === TaskPrMode::PrWhenDone && $check->pr_mode === TaskPrMode::PrWhenDone ? self::Publishing : self::WaitingForRelease;

        return new self($mode, $source, $state, $check->id, $check->commit_sha);
    }

    private static function checkOf(?Operation $operation): ?int
    {
        $check = $operation?->input['check'] ?? null;

        return is_int($check) ? $check : null;
    }

    private static function commitOf(?Operation $operation): ?string
    {
        $commit = $operation?->input['commit'] ?? null;

        return is_string($commit) ? $commit : null;
    }
}
