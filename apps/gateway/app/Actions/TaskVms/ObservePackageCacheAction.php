<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\TaskVms\ComposerPackageCache;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;

/** Called under the mirror's lock, after its default branch was fetched from the trusted upstream. */
final readonly class ObservePackageCacheAction
{
    public function __construct(private ProcessRunner $processes, private ComposerPackageCache $composer) {}

    public function execute(Project $project, string $mirror, ?string $commit): void
    {
        $snapshot = PackageCacheSnapshot::query()->find($project->id);
        if ($snapshot instanceof PackageCacheSnapshot && $snapshot->commit === $commit) {
            return;
        }
        $blobs = [];
        if ($commit !== null) {
            foreach (explode("\0", $this->git($mirror, ['ls-tree', '-r', '-z', $commit])) as $entry) {
                if (preg_match('~\A100[0-7]{3} blob ([a-f0-9]{40}(?:[a-f0-9]{24})?)\t((?:(?:apps|packages)/[^\x00]+/)?(?:composer\.lock|package-lock\.json|bun\.lock|pnpm-lock\.yaml))\z~D', $entry, $match) === 1) {
                    $blobs[$match[2]] = $match[1];
                }
            }
        }
        ksort($blobs);
        $changed = ! $snapshot instanceof PackageCacheSnapshot || $snapshot->blobs !== $blobs;
        $artifacts = $snapshot->artifacts ?? [];
        if ($changed) {
            $artifacts = [];
            foreach ($blobs as $path => $blob) {
                if (basename($path) === 'composer.lock') {
                    array_push($artifacts, ...$this->composer->read($this->git($mirror, ['cat-file', 'blob', $blob])));
                }
            }
            $artifacts = array_values(array_column($artifacts, null, 'key'));
        }
        PackageCacheSnapshot::query()->updateOrCreate(['project_id' => $project->id], ['commit' => $commit, 'blobs' => $blobs, 'artifacts' => $artifacts]);
        if ($changed) {
            foreach (app(TaskVmSettings::class)->hosts as $host) {
                FillTaskVmPackageCache::dispatch($host->nodeId)->afterCommit();
            }
        }
    }

    /** @param list<string> $arguments */
    private function git(string $mirror, array $arguments): string
    {
        $result = $this->processes->run(new ProcessInvocation(
            ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '--git-dir='.$mirror, ...$arguments],
            timeout: 60.0,
            maxOutputBytes: 32 * 1024 * 1024,
        ));
        if (! $result->succeeded() || $result->truncated) {
            throw new TaskVmException('task_vm.package_cache_lock_invalid', 'The mirror lockfile inventory could not be read.');
        }

        return $result->stdout;
    }
}
