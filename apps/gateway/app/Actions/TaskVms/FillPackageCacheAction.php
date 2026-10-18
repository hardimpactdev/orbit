<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmPackageCache;
use App\Models\Node;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class FillPackageCacheAction
{
    public function __construct(private TaskVmSettings $settings, private TaskVmPackageCache $cache) {}

    public function execute(int $hostNodeId): void
    {
        $host = $this->settings->host($hostNodeId);
        $node = Node::query()->whereKey($hostNodeId)->where('status', LifecycleStatus::Active)->firstOrFail();
        $snapshots = PackageCacheSnapshot::query()->orderBy('project_id')->get();
        $artifacts = [];
        $conflicts = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->artifacts as $artifact) {
                $previous = $artifacts[$artifact['key']] ?? null;
                if ($previous !== null && $previous['integrity'] !== null && $artifact['integrity'] !== null && $previous['integrity'] !== $artifact['integrity']) {
                    $conflicts[$artifact['key']] = 'IntegrityConflict';
                }
                if ($previous === null || $artifact['integrity'] !== null) {
                    $artifacts[$artifact['key']] = $artifact;
                }
            }
        }
        $artifacts = array_diff_key($artifacts, $conflicts);
        $token = null;
        if (array_any($artifacts, static fn (array $artifact): bool => $artifact['manager'] === 'composer') && app(GitHubAppStore::class)->credentials() !== null) {
            foreach (Project::query()->whereIn('id', $snapshots->modelKeys())->orderBy('id')->get() as $project) {
                $repository = GitHubRepository::fromOrigin($project->repository_url);
                if ($repository instanceof GitHubRepository) {
                    try {
                        $token = app(RepositoryPullRequestAccess::class)->metadataToken($repository);
                        break;
                    } catch (GitHubApiException) {
                        continue;
                    }
                }
            }
        }
        $result = $this->cache->fill($host, $node, array_values($artifacts), $token);
        $result['failures'] += $conflicts;
        DB::transaction(function () use ($snapshots, $hostNodeId, $result): void {
            foreach ($snapshots as $snapshot) {
                $current = PackageCacheSnapshot::query()->lockForUpdate()->find($snapshot->project_id);
                if (! $current instanceof PackageCacheSnapshot || $current->blobs !== $snapshot->blobs || $current->artifacts !== $snapshot->artifacts) {
                    continue;
                }
                PackageCacheEntry::query()->where('project_id', $snapshot->project_id)->where('host_node_id', $hostNodeId)->delete();
                foreach ($snapshot->artifacts as $artifact) {
                    if (! isset($result['hashes'][$artifact['key']])) {
                        continue;
                    }
                    PackageCacheEntry::query()->create([
                        'project_id' => $snapshot->project_id, 'host_node_id' => $hostNodeId,
                        'manager' => $artifact['manager'], 'package' => $artifact['package'], 'version' => $artifact['version'],
                        'key' => $artifact['key'], 'integrity' => $result['hashes'][$artifact['key']],
                    ]);
                }
                $complete = true;
                foreach ($this->settings->hosts as $configuredHost) {
                    $entries = PackageCacheEntry::query()->where('project_id', $snapshot->project_id)->where('host_node_id', $configuredHost->nodeId)->get()->keyBy(static fn (PackageCacheEntry $entry): string => $entry->package.' '.$entry->version.' '.$entry->key);
                    foreach ($snapshot->artifacts as $artifact) {
                        if (! $entries->has($artifact['package'].' '.$artifact['version'].' '.$artifact['key'])) {
                            $complete = false;
                            break 2;
                        }
                    }
                }
                $current->update(['fill_pending' => ! $complete]);
            }
        });
        foreach ($result['failures'] as $key => $failure) {
            Log::warning('Package cache artifact failed.', ['host_node_id' => $hostNodeId, 'key' => $key, 'failure' => $failure]);
        }
        if ($result['failures'] !== []) {
            throw new TaskVmException('task_vm.package_cache_failed', 'Some package cache artifacts failed; verified archives were recorded and the fill remains pending.');
        }
    }
}
