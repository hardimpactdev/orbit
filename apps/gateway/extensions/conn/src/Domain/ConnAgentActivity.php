<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\Power\AgentActivity;
use App\Domain\Instances\Power\InstanceAgentActivity;
use App\Models\Instance;
use Carbon\CarbonImmutable;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Throwable;

/**
 * The idle sleep's read of a container's T3 server (docs/reference/development-containers.md#sleep-and-wake): one
 * `GET /api/orchestration/shell` with the admin session the Gateway already holds and renews. Nothing is written in the container.
 * A connected client alone is no activity: Conn keeps a socket open to every server it knows and pings it.
 */
final readonly class ConnAgentActivity implements InstanceAgentActivity
{
    /** Run states in which a turn works. A turn in one of them keeps the Instance awake at any age. */
    private const array Working = ['preparing', 'queued', 'starting', 'running'];

    public function __construct(private ExtensionStore $extensions, private T3ServerClient $client) {}

    public function read(Instance $instance): ?AgentActivity
    {
        if (! $this->extensions->enabled('conn')) {
            return null;
        }
        $environment = ConnEnvironment::query()->where('instance_id', $instance->id)->first();
        if (! $environment instanceof ConnEnvironment) {
            return null;
        }
        try {
            return self::fromShell($this->client->shell($environment->url, $environment->admin_session));
        } catch (T3ServerException) {
            return AgentActivity::unreadable();
        }
    }

    /**
     * A turn runs when a thread's run is preparing, queued, starting or running, or waits for its own work. The last activity is the
     * newest user message, turn end, or blocking request that waits for the user: such a thread counts while its request is younger
     * than the window.
     *
     * @param  array<array-key, mixed>  $shell
     */
    public static function fromShell(array $shell): AgentActivity
    {
        $threads = $shell['threads'] ?? null;
        $archived = $shell['archivedThreads'] ?? [];
        if (! is_array($threads) || ! array_is_list($threads) || ! is_array($archived) || ! array_is_list($archived)) {
            return AgentActivity::unreadable();
        }
        $turnRuns = false;
        $last = null;
        try {
            foreach ([...$threads, ...$archived] as $thread) {
                if (! is_array($thread)) {
                    return AgentActivity::unreadable();
                }
                $status = $thread['status'] ?? null;
                $pending = is_array($thread['pendingRuntimeRequest'] ?? null) ? $thread['pendingRuntimeRequest'] : null;
                if (self::works($thread, $status, $pending)) {
                    $turnRuns = true;
                }
                $times = [
                    $thread['latestUserAuthoredMessageAt'] ?? $thread['latestUserMessageAt'] ?? null,
                    $thread['latestRunCompletedAt'] ?? null,
                    $pending['createdAt'] ?? null,
                ];
                foreach ($times as $time) {
                    if ($time === null) {
                        continue;
                    }
                    if (! is_string($time)) {
                        return AgentActivity::unreadable();
                    }
                    $unix = CarbonImmutable::parse($time)->getTimestamp();
                    $last = $last === null ? $unix : max($last, $unix);
                }
            }
        } catch (Throwable) {
            return AgentActivity::unreadable();
        }

        return AgentActivity::read($turnRuns, $last);
    }

    /**
     * Only a blocking request waits for the user. A run that waits without one waits for its own work: a delegated thread, a
     * background command or a monitor. That run works, as does a run past an async question.
     *
     * @param  array<array-key, mixed>  $thread
     * @param  array<array-key, mixed>|null  $pending
     */
    private static function works(array $thread, mixed $status, ?array $pending): bool
    {
        $background = $thread['pendingBackgroundTasks'] ?? [];

        return in_array($status, self::Working, true)
            || in_array($thread['activityRunStatus'] ?? null, self::Working, true)
            || ($thread['blockedOnThreadWait'] ?? false) === true
            || (is_array($background) && $background !== [])
            || (($status === 'waiting' || ($thread['activityRunStatus'] ?? null) === 'waiting') && ($pending === null || ($pending['blocking'] ?? true) === false));
    }
}
