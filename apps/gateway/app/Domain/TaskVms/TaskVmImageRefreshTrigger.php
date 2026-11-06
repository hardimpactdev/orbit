<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageRefreshAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

/**
 * Starts the refresh of the image and seed of every Linux task VM host (stage 6) when an operator runs
 * `task-vms:refresh-images`. Each host gets one admission, and a host with a refresh open returns that refresh. A host that
 * refuses (a build runs, no capacity) is skipped; the other hosts are not held.
 */
final readonly class TaskVmImageRefreshTrigger
{
    public function __construct(private TaskVmSettings $settings, private StartTaskVmImageRefreshAction $start) {}

    /** @return array<string,string> host name => `operation:<id>` or the refusal code */
    public function admitAll(string $reason): array
    {
        $results = [];
        foreach ($this->hosts() as $host) {
            try {
                $results[$host->name] = 'operation:'.$this->start->execute($host, 'refresh-'.$reason)->id;
            } catch (ResourceOperationException $refused) {
                Log::warning('A task image refresh was not admitted.', ['node_id' => $host->id, 'reason' => $reason, 'error_code' => $refused->errorCode]);
                $results[$host->name] = $refused->errorCode;
            }
        }

        return $results;
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
