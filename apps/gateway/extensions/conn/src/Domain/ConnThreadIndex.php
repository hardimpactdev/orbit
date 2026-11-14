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
    /** Rows older than this are read through when the list asks for them. */
    public const int StaleSeconds = 10;

    /** The bound of the poll's and the last read's request to one server: the client's own 10 seconds. */
    public const float PassTimeoutSeconds = 10.0;

    /** The bound of the list's read-through, so a server that hangs does not hold the list. */
    public const float ReadThroughTimeoutSeconds = 0.5;

    /** A title longer than this is cut. */
    public const int TitleLength = 255;

    public function __construct(private ExtensionStore $extensions, private T3ServerClient $client, private ConnServerAccess $access) {}

    /**
     * The list: the container servers the caller reaches through node access, narrowed to a Project or an Instance, and their rows.
     * While the switch is on, a running server whose last read is older than the stale window is read through first.
     *
     * @return array{environments: Collection<int, ConnEnvironment>, threads: Collection<int, ConnThread>}
     */
    public function list(Node $caller, ?int $project, ?int $instance): array
    {
        $environments = $this->access->visible($caller, ConnEnvironment::query()->whereNotNull('instance_id'))
            ->when($project !== null, static fn (Builder $query) => $query->whereHas('instance', static fn (Builder $query) => $query->where('project_id', $project)))
            ->when($instance !== null, static fn (Builder $query) => $query->where('instance_id', $instance))
            ->with('instance.runtime')->orderBy('id')->get();
        if (self::reads()) {
            $stale = CarbonImmutable::now()->subSeconds(self::StaleSeconds);
            $this->refresh($environments->filter(static fn (ConnEnvironment $environment): bool => $environment->instance?->runtime?->power_state === InstanceRuntimePowerState::Running
                && ($environment->threads_read_at === null || $environment->threads_read_at->isBefore($stale)))->values(), self::ReadThroughTimeoutSeconds);
        }
        $threads = ConnThread::query()->whereIn('conn_environment_id', $environments->modelKeys())->with(['environment', 'instance'])
            ->orderByRaw('last_user_message_at is null')->orderByDesc('last_user_message_at')->orderBy('id')->get();

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
                $results[$environment->id] = $this->skipped($environment, $reason, $reason !== 'conn.environment_asleep');
            }
        }
        foreach ($this->client->shells($servers, $timeoutSeconds) as $id => $shell) {
            $environment = $environments->firstOrFail('id', $id);
            $threads = $shell instanceof T3ServerException ? null : self::threads($shell);
            $results[$id] = $threads === null
                ? $this->skipped($environment, $shell instanceof T3ServerException ? $shell->errorCode : 'conn.response_invalid', true)
                : $this->apply($environment, $threads, $now, strlen((string) json_encode($shell)));
        }
        ksort($results);

        return array_values($results);
    }

    /** The write-through of a thread that a client or a task turn started: it is in the index before the next read. */
    public function record(ConnEnvironment $environment, string $threadId, string $title): ConnThread
    {
        $thread = ConnThread::query()->firstOrNew(['conn_environment_id' => $environment->id, 'thread_id' => $threadId]);
        $thread->fill(['instance_id' => $environment->instance_id, 'title' => mb_substr($title, 0, self::TitleLength), 'seen_at' => CarbonImmutable::now()])->save();

        return $thread;
    }

    public function recordThread(Instance $instance, string $threadId, string $title): void
    {
        $environment = $this->extensions->enabled('conn') ? ConnEnvironment::query()->where('instance_id', $instance->id)->first() : null;
        if ($environment instanceof ConnEnvironment) {
            $this->record($environment, $threadId, $title);
        }
    }

    /** The last read before a sleep, with the poll's bound; it runs only while the switch is on, and a failed read keeps the rows. */
    public function beforeSleep(Instance $instance): void
    {
        if (! self::reads() || ! $this->extensions->enabled('conn')) {
            return;
        }
        $environments = ConnEnvironment::query()->where('instance_id', $instance->id)->with('instance.runtime')->get();
        $this->refresh($environments, self::PassTimeoutSeconds);
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
     * the read began.
     *
     * @param  list<array{thread_id: string, title: string, state: ?string, needs_input: bool, last_user_message_at: ?CarbonImmutable, last_run_completed_at: ?CarbonImmutable}>  $threads
     * @return array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int}
     */
    private function apply(ConnEnvironment $environment, array $threads, CarbonImmutable $readAt, int $bytes): array
    {
        $instance = (int) $environment->instance_id;
        DB::transaction(function () use ($environment, $threads, $readAt, $instance): void {
            if ($threads !== []) {
                ConnThread::query()->upsert(array_map(static fn (array $thread): array => [...$thread, 'conn_environment_id' => $environment->id,
                    'instance_id' => $instance, 'seen_at' => $readAt], $threads), ['conn_environment_id', 'thread_id'],
                    ['instance_id', 'title', 'state', 'needs_input', 'last_user_message_at', 'last_run_completed_at', 'seen_at']);
            }
            ConnThread::query()->where('conn_environment_id', $environment->id)->where('seen_at', '<', $readAt)
                ->whereNotIn('thread_id', array_column($threads, 'thread_id'))->delete();
            $environment->forceFill(['threads_read_at' => $readAt, 'threads_read_error' => null])->save();
        });

        return ['environment_id' => $environment->environment_id, 'instance_id' => $instance, 'outcome' => 'read', 'reason' => null,
            'threads' => count($threads), 'bytes' => $bytes];
    }

    /** @return array{environment_id: string, instance_id: int, outcome: string, reason: ?string, threads: int, bytes: int} */
    private function skipped(ConnEnvironment $environment, string $reason, bool $tried): array
    {
        // A tried read records its time and reason, so the list does not try it again within the stale window.
        if ($tried) {
            $environment->forceFill(['threads_read_at' => CarbonImmutable::now(), 'threads_read_error' => $reason])->save();
        }

        return ['environment_id' => $environment->environment_id, 'instance_id' => (int) $environment->instance_id, 'outcome' => 'skipped',
            'reason' => $reason, 'threads' => ConnThread::query()->where('conn_environment_id', $environment->id)->count(), 'bytes' => 0];
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
