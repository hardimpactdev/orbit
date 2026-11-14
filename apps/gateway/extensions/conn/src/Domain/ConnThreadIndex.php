<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceThreadContribution;
use App\Models\Instance;
use App\Models\Node;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Data\ConnThreadData;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;
use Throwable;

/**
 * The thread index of container T3 servers ([Thread index](/reference/conn#thread-index)). It reads each running server's shell
 * through the admin session the Gateway holds and keeps one row per thread, with no message text beyond the title. A thread that
 * leaves the shell leaves the index; an asleep or stopped server keeps its last rows. Every read of a server waits for the
 * operator's switch `orbit.conn.thread_index_poll`; the write-through of a new thread runs without it.
 */
final readonly class ConnThreadIndex implements InstanceThreadContribution
{
    /** Rows older than this are read through when the list names their Instance. */
    public const int StaleSeconds = 10;

    /** The bound of the poll's request to one server: the client's own 10 seconds. */
    public const float PassTimeoutSeconds = 10.0;

    /** The bound of the last read before a sleep, which a sleep's caller waits for. */
    public const float SleepReadTimeoutSeconds = 3.0;

    /** The bound of the list's read-through, so a server that hangs does not hold the list. */
    public const float ReadThroughTimeoutSeconds = 0.5;

    /** Rows per upsert: each row binds 11 values, and SQLite takes at most 32,766 in one statement. */
    private const int UpsertChunk = 500;

    /** A title longer than this is cut. */
    public const int TitleLength = 255;

    public function __construct(private ExtensionStore $extensions, private T3ServerClient $client, private ConnServerAccess $access) {}

    /**
     * The list: the container servers the caller reaches through node access, narrowed to a Project or an Instance, and their rows.
     * A list of a Project or of every server reads only the index; the caller judges freshness by each server's `threads_read_at`.
     * A list of one Instance, the one a device opens a thread on, reads that server through first while the switch is on and its last
     * read is older than the stale window.
     *
     * @return array{environments: Collection<int, ConnEnvironment>, threads: list<array<string, mixed>>}
     */
    public function list(Node $caller, ?int $project, ?int $instance): array
    {
        $environments = $this->access->visible($caller, ConnEnvironment::query()->whereNotNull('instance_id'))
            ->when($project !== null, static fn (Builder $query) => $query->whereHas('instance', static fn (Builder $query) => $query->where('project_id', $project)))
            ->when($instance !== null, static fn (Builder $query) => $query->where('instance_id', $instance))
            ->with('instance.runtime')->orderBy('id')->get();
        if ($instance !== null && self::reads()) {
            $stale = CarbonImmutable::now()->subSeconds(self::StaleSeconds);
            $this->refresh($environments->filter(static fn (ConnEnvironment $environment): bool => $environment->instance?->runtime?->power_state === InstanceRuntimePowerState::Running
                && ($environment->threads_read_at === null || $environment->threads_read_at->isBefore($stale)))->values(), self::ReadThroughTimeoutSeconds);
        }
        // Each row takes its server's id and Project from the servers already loaded, and is read as a plain row (ConnThreadData::fromRow).
        $byId = $environments->keyBy('id');
        $threads = array_values(ConnThread::query()->whereIn('conn_environment_id', $byId->keys())
            ->orderByRaw('last_user_message_at is null')->orderByDesc('last_user_message_at')->orderBy('id')->toBase()->get()
            ->map(static function (\stdClass $row) use ($byId): array {
                $environment = $byId->get($row->conn_environment_id);
                assert($environment instanceof ConnEnvironment && $environment->instance !== null);

                return ConnThreadData::fromRow($row, $environment->environment_id, $environment->instance->project_id);
            })->all());

        return ['environments' => $environments, 'threads' => $threads];
    }

    /** The operator's switch for every read of a server. */
    public static function reads(): bool
    {
        return Config::boolean('orbit.conn.thread_index_poll');
    }

    /**
     * One poll pass: every container server whose Instance runs, read at once.
     *
     * @return list<array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int}>
     */
    public function pass(): array
    {
        return $this->refresh(ConnEnvironment::query()->whereNotNull('instance_id')->with('instance.runtime')->orderBy('id')->get(), self::PassTimeoutSeconds);
    }

    /**
     * Reads the given servers at once and records what each shell says. A server whose Instance does not run is not read and keeps
     * its rows; one whose session ended, or that does not answer within the bound, keeps its rows and records why.
     *
     * @param  Collection<int, ConnEnvironment>  $environments
     * @return list<array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int}>
     */
    public function refresh(Collection $environments, float $timeoutSeconds): array
    {
        $now = CarbonImmutable::now();
        $results = [];
        $servers = [];
        foreach ($environments as $environment) {
            $reason = match (true) {
                $environment->instance?->runtime?->power_state !== InstanceRuntimePowerState::Running => 'conn.environment_asleep',
                ! $environment->admin_session_expires_at->isAfter($now) => 'conn.session_expired',
                default => null,
            };
            if ($reason === null) {
                $servers[$environment->id] = ['url' => $environment->url, 'session' => $environment->admin_session];
            } else {
                $results[$environment->id] = $this->contained($environment, fn (): array => $this->skipped($environment, $reason, $reason !== 'conn.environment_asleep' ? $now : null));
            }
        }
        foreach ($this->client->shells($servers, $timeoutSeconds) as $id => $shell) {
            $environment = $environments->firstOrFail('id', $id);
            $threads = $shell instanceof T3ServerException ? null : self::threads($shell);
            $results[$id] = $this->contained($environment, fn (): array => $threads === null
                ? $this->skipped($environment, $shell instanceof T3ServerException ? $shell->errorCode : 'conn.response_invalid', $now)
                : $this->apply($environment, $threads, $now, strlen((string) json_encode($shell))));
        }
        ksort($results);

        return array_values($results);
    }

    /**
     * The write-through of a thread that a client or a task turn started: it is in the index before the next read. One upsert on the
     * row's key, so a read that writes the same thread at the same moment does not collide with it.
     *
     * @return array{thread: ConnThread, created: bool}
     */
    public function record(ConnEnvironment $environment, string $threadId, string $title): array
    {
        $key = ['conn_environment_id' => $environment->id, 'thread_id' => $threadId];
        $created = ! ConnThread::query()->where($key)->exists();
        ConnThread::query()->upsert([[...$key, 'instance_id' => $environment->instance_id, 'title' => mb_substr($title, 0, self::TitleLength),
            'seen_at' => CarbonImmutable::now()]], array_keys($key), ['instance_id', 'title', 'seen_at']);

        return ['thread' => ConnThread::query()->where($key)->firstOrFail(), 'created' => $created];
    }

    /** The task copy's write-through. A failure is reported and never fails the turn's projection. */
    public function recordThread(Instance $instance, string $threadId, string $title): void
    {
        try {
            $environment = $this->extensions->enabled('conn') ? ConnEnvironment::query()->where('instance_id', $instance->id)->first() : null;
            if ($environment instanceof ConnEnvironment) {
                $this->record($environment, $threadId, $title);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }

    /**
     * The last read before a sleep, within 3 seconds; it runs only while the switch is on. A failed read keeps the rows, is reported,
     * and never stops the sleep.
     */
    public function beforeSleep(Instance $instance): void
    {
        try {
            if (! self::reads() || ! $this->extensions->enabled('conn')) {
                return;
            }
            $environments = ConnEnvironment::query()->where('instance_id', $instance->id)->with('instance.runtime')->get();
            $this->refresh($environments, self::SleepReadTimeoutSeconds);
        } catch (Throwable $error) {
            report($error);
        }
    }

    /**
     * The index rows of one shell, or null when the shell is not one this index understands.
     *
     * @param  array<array-key, mixed>  $shell
     * @return list<array{thread_id: string, title: string, state: ?string, needs_input: bool, last_user_message_at: ?CarbonImmutable, last_run_completed_at: ?CarbonImmutable}>|null
     */
    public static function threads(array $shell): ?array
    {
        $threads = $shell['threads'] ?? null;
        if (! is_array($threads) || ! array_is_list($threads)) {
            return null;
        }
        $rows = [];
        try {
            foreach ($threads as $thread) {
                if (! is_array($thread) || ! is_string($thread['id'] ?? null) || ! is_string($thread['title'] ?? null)) {
                    return null;
                }
                $status = $thread['status'] ?? null;
                $pending = $thread['pendingRuntimeRequest'] ?? null;
                if (($status !== null && ! is_string($status)) || ($pending !== null && ! is_array($pending))) {
                    return null;
                }
                $rows[] = [
                    'thread_id' => $thread['id'],
                    'title' => mb_substr($thread['title'], 0, self::TitleLength),
                    'state' => $status,
                    'needs_input' => ConnAgentActivity::waitsForUser($pending),
                    'last_user_message_at' => self::time($thread['latestUserAuthoredMessageAt'] ?? $thread['latestUserMessageAt'] ?? null),
                    'last_run_completed_at' => self::time($thread['latestRunCompletedAt'] ?? null),
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return $rows;
    }

    /**
     * Writes one server's read: its threads are upserted, and a row it no longer lists leaves, unless a write-through recorded it after
     * the read began. A read that began before the server's last recorded read is dropped: it would bring back what that read removed.
     *
     * @param  list<array{thread_id: string, title: string, state: ?string, needs_input: bool, last_user_message_at: ?CarbonImmutable, last_run_completed_at: ?CarbonImmutable}>  $threads
     * @return array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int}
     */
    private function apply(ConnEnvironment $environment, array $threads, CarbonImmutable $readAt, int $bytes): array
    {
        $instance = (int) $environment->instance_id;
        $applied = DB::transaction(function () use ($environment, $threads, $readAt, $instance): bool {
            $last = ConnEnvironment::query()->whereKey($environment->id)->toBase()->value('threads_read_at');
            if (is_string($last) && CarbonImmutable::parse($last)->isAfter($readAt)) {
                return false;
            }
            foreach (array_chunk($threads, self::UpsertChunk) as $chunk) {
                ConnThread::query()->upsert(array_map(static fn (array $thread): array => [...$thread, 'conn_environment_id' => $environment->id,
                    'instance_id' => $instance, 'seen_at' => $readAt], $chunk), ['conn_environment_id', 'thread_id'],
                    ['instance_id', 'title', 'state', 'needs_input', 'last_user_message_at', 'last_run_completed_at', 'seen_at']);
            }
            // Every listed thread now has this read's time; an older row is one the shell no longer lists.
            ConnThread::query()->where('conn_environment_id', $environment->id)->where('seen_at', '<', $readAt)->delete();
            $environment->forceFill(['threads_read_at' => $readAt, 'threads_read_error' => null])->save();

            return true;
        });

        return ['environment_id' => $environment->environment_id, 'instance_id' => $instance, 'outcome' => $applied ? 'read' : 'superseded',
            'reason' => null, 'threads' => count($threads), 'bytes' => $bytes];
    }

    /** @return array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int} */
    private function skipped(ConnEnvironment $environment, string $reason, ?CarbonImmutable $triedAt): array
    {
        // A tried read records when it began and why it failed, so the list does not try it again within the stale window.
        if ($triedAt !== null) {
            $environment->forceFill(['threads_read_at' => $triedAt, 'threads_read_error' => $reason])->save();
        }

        return ['environment_id' => $environment->environment_id, 'instance_id' => (int) $environment->instance_id, 'outcome' => 'skipped',
            'reason' => $reason, 'threads' => ConnThread::query()->where('conn_environment_id', $environment->id)->count(), 'bytes' => 0];
    }

    /**
     * One server's write, contained: a database error is reported and the server is skipped, so it never ends the pass or fails the
     * caller.
     *
     * @param  \Closure(): array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int}  $write
     * @return array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int}
     */
    private function contained(ConnEnvironment $environment, \Closure $write): array
    {
        try {
            return $write();
        } catch (Throwable $error) {
            report($error);

            return ['environment_id' => $environment->environment_id, 'instance_id' => (int) $environment->instance_id, 'outcome' => 'skipped',
                'reason' => 'conn.index_write_failed', 'threads' => 0, 'bytes' => 0];
        }
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value === null => null,
            is_string($value) => CarbonImmutable::parse($value),
            default => throw new \UnexpectedValueException('A thread time is not a string.'),
        };
    }
}
