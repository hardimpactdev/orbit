<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\StartTaskVmImageRefreshAction;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

/**
 * Starts the refresh of the image and seed of every Linux task VM host (stage 6): after a release that went live and changed
 * what the image carries, and daily. Each host gets one admission, and a host with a refresh open returns that refresh, so
 * the two triggers and a retry never run twice on a host. A host that refuses (a build runs, no capacity) is skipped and
 * asked again by the next trigger; the other hosts are not held.
 */
final readonly class TaskVmImageRefreshTrigger
{
    public function __construct(private TaskVmSettings $settings, private TaskVmImageInputs $inputs, private StartTaskVmImageRefreshAction $start) {}

    /**
     * The refresh after a release: only when the release changed playbooks, pins or lock files since the one it replaced.
     *
     * @return array<string,string> host name => the Operation, or the refusal code, or `unchanged`
     */
    public function afterRelease(string $releaseId, string $previousId): array
    {
        $layout = GatewayReleaseLayout::fromConfig();
        if (! $this->inputs->changed($layout->releasePath($previousId), $layout->releasePath($releaseId))) {
            return ['*' => 'unchanged'];
        }

        return $this->admitAll('release');
    }

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
