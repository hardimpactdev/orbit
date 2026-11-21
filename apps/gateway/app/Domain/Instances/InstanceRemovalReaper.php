<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts a failed destroy again, through the same admission as `instance:destroy`: the same removal, the same `force`
 * (docs/reference/instance-removal.md#retry-and-ownership). It waits after each failure, longer each time, and reports the removal
 * stuck once its starts have failed MaxRetries times. A refused admission is no start: the next pass tries again. Each refusal is
 * recorded on the removal (`start_refusal`, which Doctor reports) and in one Activity entry with its error code, never only in the log.
 */
final readonly class InstanceRemovalReaper
{
    /** After this many failed reaper starts the removal is stuck: Doctor reports it and one Activity entry records it. */
    public const int MaxRetries = 3;

    /** Minutes from a failure to the next start, by the starts the reaper made already; the last one repeats. */
    public const array DelayMinutes = [5, 15, 60, 360];

    /** The `triggered_by` of the Operations the reaper starts and the command of its Activity entry. */
    public const string Trigger = 'orbit:instance-removal-reap';

    public const string StuckCode = 'instance.removal_stuck';

    /** A removal that failed with one of these needs a person: the reaper never starts it and reports it stuck at once. */
    public const array PersonCodes = ['instance.removal_dataset_taken'];

    /** The Activity entry and the Doctor issue of a refused start; the entry's error code is the refusal's own. */
    public const string RefusedCode = 'instance.removal_retry_refused';

    /**
     * The refusal of a removal that holds no saved intent (an older removal, such as one from 12 November 2026): admission can only
     * refuse it with `instance.removal_conflict`, since it replays the saved intent. It needs a person, so it is stuck at once.
     */
    public const string IntentMissingCode = 'instance.removal_intent_missing';

    /** The refusal code of a start that failed with an error other than an admission refusal. */
    public const string StartFailedCode = 'instance.removal_retry_failed';

    /** A refused start with one of these needs a person: the reaper never starts the removal again and reports it stuck at once. */
    public const array PersonRefusals = [self::IntentMissingCode];

    public function __construct(private StartInstanceDestroyAction $destroy, private OperationIdempotency $idempotency) {}

    public static function delayMinutes(int $retries): int
    {
        return self::DelayMinutes[min($retries, count(self::DelayMinutes) - 1)];
    }

    /** The one rule for a stuck removal: the reaper's starts failed MaxRetries times and the removal is failed again. */
    public static function stuck(InstanceRemoval $removal): bool
    {
        return $removal->status === InstanceRemovalStatus::Failed && $removal->kind === InstanceRemovalKind::Destroy
            && ($removal->retry_count >= self::MaxRetries || in_array($removal->error_code, self::PersonCodes, true)
                || in_array($removal->start_refusal, self::PersonRefusals, true));
    }

    /** @return list<Operation> the Operations this pass started */
    public function run(): array
    {
        // The operator's off switch, ORBIT_INSTANCE_REMOVAL_REAPER: the pass does nothing.
        if (! config()->boolean('instances.removal_reaper')) {
            return [];
        }
        $started = [];
        $removals = InstanceRemoval::query()->where('status', InstanceRemovalStatus::Failed)->where('kind', InstanceRemovalKind::Destroy)
            ->orderBy('created_at')->get();
        foreach ($removals as $removal) {
            try {
                $operation = $this->reap($removal);
            } catch (ResourceOperationException $refusal) {
                // Admission refused: a failed retry like a failed run. It counts, and the next start waits the next delay from now, so
                // a removal that admission refuses for good reports stuck after MaxRetries.
                $this->refused($removal, $refusal->errorCode, $refusal->getMessage());

                continue;
            } catch (Throwable $exception) {
                // Any other error counts and shows the same way, so no start fails only in the log.
                report($exception);
                $this->refused($removal, self::StartFailedCode, Str::limit($exception::class.': '.$exception->getMessage(), 500));

                continue;
            }
            if ($operation !== null) {
                $started[] = $operation;
            }
        }

        return $started;
    }

    private function reap(InstanceRemoval $removal): ?Operation
    {
        $instance = Instance::query()->find($removal->requested_instance_id);
        if (! $instance instanceof Instance || $instance->status !== InstanceState::Removing
            || ! InstanceRemovalMember::query()->live()->where('instance_removal_id', $removal->id)->where('instance_id', $instance->id)->exists()
            || InstanceTransfer::query()->where('instance_id', $instance->id)->open()->exists()
            || Operation::query()->exceptFleetWait()->whereNotIn('status', OperationStatus::terminalValues())
                // Its own target, or the Instance lock that a create or completion holds: admission would cancel or wait behind those.
                ->where(fn ($query) => $query->where(fn ($own) => $own->where('target_type', 'instance')->where('target_id', $instance->id))
                    ->orWhere('lock_key', OperationStore::lockKey('instance', $instance->id)))->exists()) {
            return null;
        }
        if ($removal->native_intent === null && $removal->start_refusal !== self::IntentMissingCode) {
            // No saved intent: admission would refuse it every time with instance.removal_conflict. Flag it now; never start it.
            $this->refused($removal, self::IntentMissingCode, 'The removal holds no saved intent, so admission cannot start it again.', count: false);
            $removal->start_refusal = self::IntentMissingCode;
        }
        if (self::stuck($removal) && $removal->stuck_at === null) {
            $this->reportStuck($removal, $instance);
        }
        if (in_array($removal->error_code, self::PersonCodes, true) || in_array($removal->start_refusal, self::PersonRefusals, true)) {
            return null;
        }
        // The removal record changes at each failed run and each refused start, so its last change is when the last try ended; a
        // failed removal without any Operation counts from it too.
        $failedAt = CarbonImmutable::instance($removal->updated_at ?? $removal->created_at ?? now());
        if (CarbonImmutable::now()->lt($failedAt->addMinutes(self::delayMinutes($removal->retry_count)))) {
            return null;
        }
        $key = "removal-reaper:{$removal->id}:{$removal->retry_count}";
        $retries = $removal->retry_count;

        return $this->idempotency->under(InstanceDestroyKind::forInstance($instance), $key, hash('sha256', "instance.destroy|{$key}"),
            fn (): Operation => $this->destroy->execute($instance, $removal->force, self::Trigger, (string) Str::uuid(),
                within: static function (Closure $record) use ($removal, $retries): Operation {
                    $operation = $record();
                    // Counted with the admission: a refused one rolls the count back with it.
                    if ($operation->wasRecentlyCreated) {
                        InstanceRemoval::query()->whereKey($removal->id)->where('retry_count', $retries)
                            ->update(['retry_count' => $retries + 1, 'start_refusal' => null]);
                    }

                    return $operation;
                }));
    }

    /**
     * A start that did not happen: the removal records the code for Doctor, and one Activity entry records the refusal with its code.
     * `$count`: it counts as a failed retry, and the next start waits the next delay from now.
     */
    private function refused(InstanceRemoval $removal, string $code, string $message, bool $count = true): void
    {
        Log::info('Removal reaper start refused', ['removal_id' => $removal->id, 'error_code' => $code]);
        $retries = $removal->retry_count + ($count ? 1 : 0);
        DB::transaction(function () use ($removal, $code, $message, $retries): void {
            if (InstanceRemoval::query()->whereKey($removal->id)->where('status', InstanceRemovalStatus::Failed)
                ->where('retry_count', $removal->retry_count)->update(['retry_count' => $retries, 'start_refusal' => $code]) !== 1) {
                return;
            }
            Activity::query()->create(['log_name' => 'instances', 'description' => self::RefusedCode,
                'subject_type' => Instance::MorphAlias, 'subject_id' => $removal->requested_instance_id,
                'command' => self::Trigger, 'request_id' => (string) Str::uuid(),
                'status' => 'failed', 'error_code' => $code,
                'properties' => ['instance_id' => $removal->requested_instance_id, 'removal_id' => $removal->id, 'retry_count' => $retries,
                    'message' => $message, 'failed_step' => $removal->failed_step?->value, 'removal_error_code' => $removal->error_code]]);
        });
    }

    /** Once per removal: the Activity entry; Doctor reports the issue for as long as the removal stays stuck (FactDoctor). */
    private function reportStuck(InstanceRemoval $removal, Instance $instance): void
    {
        DB::transaction(function () use ($removal, $instance): void {
            if (InstanceRemoval::query()->whereKey($removal->id)->whereNull('stuck_at')->update(['stuck_at' => now()]) !== 1) {
                return;
            }
            Activity::query()->create(['log_name' => 'instances', 'description' => self::StuckCode,
                'subject_type' => Instance::MorphAlias, 'subject_id' => $instance->id,
                'command' => self::Trigger, 'request_id' => (string) Str::uuid(),
                'status' => 'failed', 'error_code' => self::StuckCode,
                'properties' => ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'removal_id' => $removal->id,
                    'retry_count' => $removal->retry_count, 'failed_step' => $removal->failed_step?->value, 'removal_error_code' => $removal->error_code,
                    'start_refusal' => $removal->start_refusal]]);
        });
        $removal->stuck_at = now();
    }
}
