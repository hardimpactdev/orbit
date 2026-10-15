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

final readonly class FillPackageCacheAction
{
    public function __construct(private TaskVmSettings $settings, private TaskVmPackageCache $cache) {}

    public function execute(int $hostNodeId): void
    {
        $host = $this->settings->host($hostNodeId);
        $node = Node::query()->whereKey($hostNodeId)->where('status', LifecycleStatus::Active)->firstOrFail();
        $snapshots = PackageCacheSnapshot::query()->orderBy('project_id')->get();
        $artifacts = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->artifacts as $artifact) {
                $previous = $artifacts[$artifact['key']] ?? null;
                if ($previous !== null && $previous['integrity'] !== null && $artifact['integrity'] !== null && $previous['integrity'] !== $artifact['integrity']) {
                    throw new TaskVmException('task_vm.package_cache_integrity_conflict', 'Projects disagree about the integrity of one shared archive.');
                }
                if ($previous === null || $artifact['integrity'] !== null) {
                    $artifacts[$artifact['key']] = $artifact;
                }
            }
        }
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
        $hashes = $this->cache->fill($host, $node, array_values($artifacts), $token);
        DB::transaction(function () use ($snapshots, $hostNodeId, $hashes): void {
            foreach ($snapshots as $snapshot) {
                $current = PackageCacheSnapshot::query()->find($snapshot->project_id);
                if (! $current instanceof PackageCacheSnapshot || $current->blobs !== $snapshot->blobs) {
                    continue;
                }
                PackageCacheEntry::query()->where('project_id', $snapshot->project_id)->where('host_node_id', $hostNodeId)->delete();
                foreach ($snapshot->artifacts as $artifact) {
                    if (! isset($hashes[$artifact['key']])) {
                        continue;
                    }
                    PackageCacheEntry::query()->create([
                        'project_id' => $snapshot->project_id, 'host_node_id' => $hostNodeId,
                        'manager' => $artifact['manager'], 'package' => $artifact['package'], 'version' => $artifact['version'],
                        'key' => $artifact['key'], 'integrity' => $hashes[$artifact['key']],
                    ]);
                }
            }
        });
    }
}
