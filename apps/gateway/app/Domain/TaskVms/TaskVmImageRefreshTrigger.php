<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageRefreshAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Starts the refresh of the base of every Linux task VM host (stage 6): in every Gateway release, and daily. Each host gets one
 * admission, and a host with a refresh open keeps it and queues the newest commit behind it, so the triggers and a retry never run
 * twice on a host. A host that refuses (no capacity) is skipped and asked again by the next trigger; the other hosts are not held. A
 * release asks again, a bounded number of rounds, a host whose admission Swamp could not take while it reloaded.
 */
final readonly class TaskVmImageRefreshTrigger
{
    /** The refusal of an admission whose execution copy Swamp could not store. */
    public const string SwampUnavailable = 'swamp.vault_sync_unavailable';

    /** Seconds between two rounds of admissions that Swamp could not take. */
    public const int Pause = 5;

    public function __construct(private TaskVmSettings $settings, private StartTaskVmImageRefreshAction $start) {}

    /**
     * Every host, to `$commit` or to the release the Gateway runs.
     *
     * @return array<string,string> host name => `operation:<id>` or the refusal code
     */
    public function admitAll(string $reason, ?string $commit = null, int $attempts = 1): array
    {
        return self::rounds($this->hosts(), fn (Node $host): string => $this->admit($host, $reason, $commit), $attempts);
    }

    /**
     * The result of each host after at most `$attempts` rounds. A host whose admission Swamp could not take, as while a release reloads it,
     * is asked again after a pause; a host that refused for another reason, or was admitted, is not.
     *
     * @param  list<Node>  $hosts
     * @param  Closure(Node): string  $admit
     * @return array<string,string> host name => `operation:<id>` or the refusal code
     */
    public static function rounds(array $hosts, Closure $admit, int $attempts): array
    {
        $results = [];
        for ($round = 1; $hosts !== []; $round++) {
            $again = [];
            foreach ($hosts as $host) {
                $results[$host->name] = $admit($host);
                if ($results[$host->name] === self::SwampUnavailable && $round < $attempts) {
                    $again[] = $host;
                }
            }
            $hosts = $again;
            if ($hosts !== []) {
                Sleep::for(self::Pause)->seconds();
            }
        }

        return $results;
    }

    /** One host, as a run that ended asks for the commit that was queued behind it. */
    public function admitHost(int $hostId, string $reason, string $commit): string
    {
        foreach ($this->hosts() as $host) {
            if ($host->id === $hostId) {
                return $this->admit($host, $reason, $commit);
            }
        }

        return 'task_vm.host_unavailable';
    }

    /** @return string `operation:<id>` or the refusal code */
    private function admit(Node $host, string $reason, ?string $commit): string
    {
        try {
            return 'operation:'.$this->start->execute($host, 'refresh-'.$reason, $commit)->id;
        } catch (ResourceOperationException $refused) {
            Log::warning('A task image refresh was not admitted.', ['node_id' => $host->id, 'reason' => $reason, 'error_code' => $refused->errorCode]);

            return $refused->errorCode;
        }
    }

    /** @return list<Node> the active Linux task VM hosts */
    private function hosts(): array
    {
        if (! $this->settings->enabled) {
            return [];
        }
        $nodes = Node::query()->whereIn('id', array_map(static fn (TaskVmHost $host): int => $host->nodeId, $this->settings->hosts))->where('platform', 'linux')->orderBy('id')->get();

        return array_values($nodes->all());
    }
}
