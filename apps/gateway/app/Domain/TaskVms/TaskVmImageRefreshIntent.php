<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCapacityException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admits the refresh of one task VM host's base (docs/cli/task/vm-image-refresh.mmd) as one run on the host: the host's base VM starts,
 * converges as a task start would, stops and is snapshotted; the snapshot is proven with one task start in a free slot, and the
 * projection promotes it. The intent holds everything the run needs; no step calls the Gateway.
 */
final readonly class TaskVmImageRefreshIntent
{
    public const string Kind = 'task_vm.image_refresh';

    /** Where the proof Swamp Club key lives on a task VM; the nested Gateway reads it through a disk device (swamp/ansible/task-vm-role.yml). */
    public const string ClubKey = '/etc/orbit/swamp-club-api-key';

    /** The key of the Operation state that holds the commit to refresh to when the open refresh ends. */
    public const string Next = 'refresh_next';

    public function __construct(private StartOperationAction $operations, private OperationStore $store, private TaskVmSettings $settings, private TaskVmPromotedImage $images,
        private SwampSshExecutionInput $ssh, private SwampCommandStep $shared, private TaskVmConvergeInput $converge, private TaskVmSlotRuns $runs, private TaskVmSlotPool $slots) {}

    /**
     * The refresh that is open on `$host`, if any: a second admission returns it.
     *
     * @phpstan-impure
     */
    public static function open(Node|int $host): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'task_vm_host')->where('target_id', $host instanceof Node ? $host->id : $host)
            ->whereNotIn('status', OperationStatus::terminalValues())->first();
    }

    /** Refuses a host that cannot refresh: not a configured, active task VM host. */
    public function assertCanRefresh(Node $host): TaskVmHost
    {
        if (! $this->settings->enabled || ! $this->settings->hasHost($host->id) || $host->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.host_unavailable', 'A refresh needs an active, configured task VM host.', 409);
        }

        return $this->settings->host($host->id);
    }

    /**
     * Admits the refresh of `$host` to `$commit`, or to the release the Gateway runs. A refresh that is open on the host stays; a different
     * `$commit` waits behind it, one pending commit per host, the newest wins, and the run that ends admits it (`TaskVmImageRefreshProjection`).
     * With `$fromStock`, the run first deletes the base VM and its seed and builds the base from the stock image; it is refused while a
     * refresh is open, which was admitted without that.
     */
    public function start(Node $host, string $caller, ?string $request = null, ?string $commit = null, bool $fromStock = false): Operation
    {
        if ($fromStock && self::open($host) instanceof Operation) {
            throw new ResourceOperationException('operation.target_busy', 'A refresh of this host is open; rebuild after it ends.', 409);
        }
        if (($open = $this->openOrQueue($host, $commit)) instanceof Operation) {
            return $open;
        }
        $lock = Cache::lock('task-vm-image-refresh-'.$host->id, 120);
        if (! $lock->get()) {
            return $this->openOrQueue($host, $commit) ?? throw new ResourceOperationException('operation.target_busy', 'Another refresh of this host is being admitted.', 409);
        }
        try {
            // The proof's claim takes its identity in the admission transaction. A rebuild checks again under the lock.
            if ($fromStock && self::open($host) instanceof Operation) {
                throw new ResourceOperationException('operation.target_busy', 'A refresh of this host is open; rebuild after it ends.', 409);
            }

            return $this->openOrQueue($host, $commit) ?? DB::transaction(function () use ($host, $caller, $request, $commit, $fromStock): Operation {
                // The run deletes the base before it builds the new one, so the recorded base is withdrawn at once: starts on the host are
                // refused until the run promotes the new base, and after a failure the next refresh builds the base as on a new host.
                if ($fromStock) {
                    $this->images->withdraw($host);
                }

                return $this->operations->execute(self::Kind, 'task_vm_host', $host->id, $host->id, $this->input($host, $commit, $fromStock), $caller, $request,
                    lockKey: OperationStore::lockKey('task_vm_host', $host->id));
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * The refresh that is open on `$host`, told to refresh to `$commit` when it ends if it runs for another commit; null when none is open.
     *
     * @phpstan-impure
     */
    private function openOrQueue(Node $host, ?string $commit): ?Operation
    {
        return DB::transaction(function () use ($host, $commit): ?Operation {
            $open = self::open($host);
            $open = $open instanceof Operation ? Operation::query()->lockForUpdate()->find($open->id) : null;
            if (! $open instanceof Operation || $open->status->terminal()) {
                return null;
            }
            if ($commit !== null && ($open->input['commit'] ?? null) !== $commit) {
                $this->store->change($open, ['state' => [...($open->state ?? []), self::Next => $commit]]);
            }

            return $open;
        });
    }

    /**
     * The full intent of the run, for `$commit` or the release the Gateway runs. The base VM and seed names follow the host's name, so a
     * rerun after a failure finds and replaces what the failed run left.
     *
     * @return array<string,mixed>
     */
    public function input(Node $host, ?string $commit = null, bool $fromStock = false): array
    {
        $location = $this->assertCanRefresh($host);
        // A rebuild from stock has no base to roll back to or to keep: the run deletes it before it builds the new one.
        $base = $fromStock ? null : $this->images->recorded($host, $location);
        $commit ??= $this->converge->releaseCommit();
        $club = config('swamp.proof_club_api_key');
        if (! is_string($club) || trim($club) === '') {
            throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'The refresh needs the proof Swamp Club key for the nested Gateway.', 503);
        }
        $converge = $this->converge->for($host, $commit);
        $jump = $this->ssh->forNode($host);
        $identity = $this->ssh->identity();
        $large = TaskVmSize::Large->resources();
        // One task start with a slot and a fresh identity proves the candidate, as a group gets it; the refresh releases it when it ends.
        try {
            $slot = $this->slots->take(null, $host->id);
        } catch (TaskCapacityException $full) {
            throw new ResourceOperationException('task_vm.image_refresh_unavailable', 'A refresh proves its candidate with one task start; '.$full->getMessage(), 409);
        }
        // The run's snapshot, named at admission: the run takes it, and a discard after a failure removes it.
        $snapshot = 'r'.now()->utc()->format('YmdHis');
        $start = $this->runs->claim($slot, null, $commit, $snapshot);

        return [
            'commit' => $commit, 'recipe_identity' => $converge['recipe_identity'], 'task_vm_id' => $slot->id,
            'intent' => [
                // The base VM holds the nested fleet, so it has the resources of a large task VM; a task start copies its snapshot. A host
                // without a base VM gets it in the `base-start` phase, once, from the stock image and an empty seed volume.
                'host' => ['node_id' => $host->id, 'ssh' => $jump, 'project' => $location->project, 'pool' => $location->pool, 'network' => $location->network,
                    'cidr' => $location->cidr, 'zfs_dataset' => $location->zfsDataset, 'pool_size' => $location->poolSize,
                    'image' => TaskVmHost::SourceImage, 'current' => $base['snapshot'] ?? null, 'snapshot' => $snapshot, 'rebuild' => $fromStock,
                    'clean' => TaskVmHostLeftovers::intent(),
                    'base' => ['vm' => TaskVmPromotedImage::baseVm($host), 'seed' => TaskVmPromotedImage::baseSeed($host), 'seed_size' => TaskVmVolumes::SeedSize,
                        'cpus' => $large->cpus, 'memory' => $large->memory, 'disk' => $location->disk,
                        'launch_config' => TaskVmLaunchConfig::for($identity['public_key']), 'club_key' => self::ClubKey]],
                'converge' => $converge['converge'],
                // The Pi server is fetched when the base's recipe is older than the release's, and for a base VM that has no recipe yet.
                'with_pi' => $base === null || $base['recipe_identity'] !== $converge['recipe_identity'],
                'prove' => ['start' => $start, 'release' => $this->runs->release($slot)],
            ],
            'execution_secrets' => ['club' => $this->shared->executionCopy('orbit-control-inputs', 'swamp-proof-club-api-key', $club)],
        ];
    }
}
