<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageRefreshAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

/**
 * Starts the refresh of the base of every Linux task VM host (stage 6): in every Gateway release, and daily. Each host gets one
 * admission, and a host with a refresh open keeps it and queues the newest commit behind it, so the triggers and a retry never run
 * twice on a host. A host that refuses (no capacity) is skipped and asked again by the next trigger; the other hosts are not held.
 */
final readonly class TaskVmImageRefreshTrigger
{
    public function __construct(private TaskVmSettings $settings, private StartTaskVmImageRefreshAction $start) {}

    /**
     * Every host, to `$commit` or to the release the Gateway runs.
     *
     * @return array<string,string> host name => `operation:<id>` or the refusal code
     */
    public function admitAll(string $reason, ?string $commit = null): array
    {
        $results = [];
        foreach ($this->hosts() as $host) {
            $results[$host->name] = $this->admit($host, $reason, $commit);
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
