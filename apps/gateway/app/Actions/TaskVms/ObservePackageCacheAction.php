<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\TaskVms\ComposerPackageCache;
use App\Infrastructure\TaskVms\NpmPackageCache;
use App\Jobs\TaskVms\FillTaskVmPackageCache;
use App\Models\PackageCacheEntry;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Called under the mirror's lock, after its default branch was fetched from the trusted upstream. */
final readonly class ObservePackageCacheAction
{
    public function __construct(private ProcessRunner $processes, private ComposerPackageCache $composer, private NpmPackageCache $npm) {}

    public function execute(Project $project, string $mirror, ?string $commit): void
    {
        $snapshot = PackageCacheSnapshot::query()->find($project->id);
        if ($snapshot instanceof PackageCacheSnapshot && $snapshot->commit === $commit) {
            if ($snapshot->fill_pending) {
                $this->dispatch();
            }

            return;
        }
        $files = [];
        if ($commit !== null) {
            foreach (explode("\0", $this->git($mirror, ['ls-tree', '-r', '-z', $commit])) as $entry) {
                if (preg_match('~\A100[0-7]{3} blob ([a-f0-9]{40}(?:[a-f0-9]{24})?)\t((?:(?:apps|packages)/[^\x00]+/)?(?:composer\.json|package\.json|composer\.lock|package-lock\.json|bun\.lock|pnpm-lock\.yaml))\z~D', $entry, $match) === 1) {
                    $files[$match[2]] = $match[1];
                }
            }
        }
        $blobs = [];
        foreach ($files as $path => $blob) {
            $filename = basename($path);
            if (in_array($filename, ['composer.json', 'package.json'], true)) {
                continue;
            }
            $directory = dirname($path) === '.' ? '' : dirname($path).'/';
            $manifest = $filename === 'composer.lock' ? 'composer.json' : 'package.json';
            if (isset($files[$directory.$manifest])) {
                $blobs[$path] = $blob;
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
                } else {
                    array_push($artifacts, ...$this->npm->read(basename($path), $this->git($mirror, ['cat-file', 'blob', $blob])));
                }
            }
            $unique = [];
            foreach ($artifacts as $artifact) {
                $unique[$artifact['package'].' '.$artifact['version'].' '.$artifact['key']] = $artifact;
            }
            $artifacts = array_values($unique);
        }
        $pending = DB::transaction(function () use ($project, $commit, $blobs, $artifacts, $changed): bool {
            $current = PackageCacheSnapshot::query()->lockForUpdate()->find($project->id);
            $pending = $changed || ($current->fill_pending ?? false);
            if ($changed) {
                PackageCacheEntry::query()->where('project_id', $project->id)->delete();
            }
            PackageCacheSnapshot::query()->updateOrCreate(['project_id' => $project->id], ['commit' => $commit, 'blobs' => $blobs, 'artifacts' => $artifacts, 'fill_pending' => $pending]);

            return $pending;
        });
        if ($pending) {
            $this->dispatch();
        }
    }

    private function dispatch(): void
    {
        foreach (app(TaskVmSettings::class)->hosts as $host) {
            try {
                FillTaskVmPackageCache::dispatch($host->nodeId)->afterCommit();
            } catch (Throwable $exception) {
                new UniqueLock(app(Repository::class))->release(new FillTaskVmPackageCache($host->nodeId));

                throw $exception;
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
