<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\Tasks\DeliverablePathRepository;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * Reads an immutable tree of the origin in a disposable bare repository. The tree is only listed, so no project code runs.
 */
final readonly class NativeDeliverablePathRepository implements DeliverablePathRepository
{
    private const int MaxTreeBytes = 16_777_216;

    public function __construct(private ProcessRunner $processes, private RepositoryReadAccess $access, private Filesystem $filesystem, private ?RemoteBranchHeads $heads = null) {}

    public function defaultBranchCommit(Project $project): string
    {
        try {
            return ($this->heads ?? new GitRemoteBranchHeads($this->processes, $this->access))->head($project, (string) $project->default_branch);
        } catch (ResourceOperationException) {
            throw $this->failure();
        }
    }

    public function files(Project $project, string $commit): array
    {
        if (preg_match('/\A[0-9a-f]{7,64}\z/i', $commit) !== 1) {
            throw $this->failure();
        }

        return $this->originFiles($project, $commit);
    }

    /** @return list<string> */
    private function originFiles(Project $project, string $commit): array
    {
        $directory = sys_get_temp_dir().'/orbit-deliverable-path-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw $this->failure();
        }
        try {
            $this->run(['git', 'init', '--bare', '--', $directory], $project);
            $this->run(['git', '-C', $directory, 'fetch', '--no-tags', '--depth=1', '--filter=blob:none', '--', $project->repositoryUrl(), $commit], $project);
            $output = $this->run(['git', '-C', $directory, 'ls-tree', '-r', '-z', $commit], $project, self::MaxTreeBytes);

            return $this->parseTree($output);
        } finally {
            $this->filesystem->deleteDirectory($directory);
        }
    }

    /** @return list<string> */
    private function parseTree(string $output): array
    {
        $files = [];
        foreach (explode("\0", $output) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/\A[0-7]{6} (blob|commit) [0-9a-f]+\t(.+)\z/s', $entry, $matches) !== 1) {
                throw $this->failure();
            }
            if ($matches[1] === 'blob') {
                $files[] = $matches[2];
            }
        }

        return $files;
    }

    /** @param non-empty-list<string> $arguments */
    private function run(array $arguments, Project $project, int $maxOutputBytes = 65_536): string
    {
        try {
            $result = $this->processes->run(new ProcessInvocation(
                arguments: ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', ...array_slice($arguments, 1)],
                timeout: 60.0,
                maxOutputBytes: $maxOutputBytes,
                environment: $this->access->for($project->repositoryUrl(), $project->source_access)->variables,
            ));
        } catch (Throwable) {
            throw $this->failure();
        }
        if (! $result->succeeded() || $result->truncated) {
            throw $this->failure();
        }

        return $result->stdout;
    }

    private function failure(): ResourceOperationException
    {
        return new ResourceOperationException(errorCode: 'tasks.deliverable_base_unavailable', message: 'The repository base for deliverable path validation could not be read.');
    }
}
