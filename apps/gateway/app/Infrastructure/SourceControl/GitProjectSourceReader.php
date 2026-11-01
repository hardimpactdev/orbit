<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Fetches the branch head without blobs into a scratch repository, then reads only the named files, with the Project's
 * read credential in the environment ([credentialed Git](/reference/instance-setup#credentialed-git)).
 */
final readonly class GitProjectSourceReader implements ProjectSourceReader
{
    private const int MaximumFileBytes = 1_048_576;

    public function __construct(private ProcessRunner $processes, private RepositoryReadAccess $access) {}

    public function read(#[SensitiveParameter] string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths): array
    {
        $directory = storage_path('framework/project-source-'.Str::uuid());
        $environment = $this->access->for($repositoryUrl, $access)->variables;
        try {
            File::ensureDirectoryExists($directory, 0700);
            $this->git($directory, $environment, ['init', '--quiet']);
            $this->git($directory, $environment, ['remote', 'add', 'origin', $repositoryUrl]);
            $this->git($directory, $environment, ['config', 'extensions.partialClone', 'origin']);
            $this->git($directory, $environment, ['config', 'remote.origin.promisor', 'true']);
            $this->git($directory, $environment, ['fetch', '--quiet', '--depth=1', '--filter=blob:none', '--no-tags', 'origin',
                'refs/heads/'.GitBranchName::validate($branch)]);
            $files = [];
            foreach ($paths as $path) {
                $entry = $this->git($directory, $environment, ['ls-tree', '-z', 'FETCH_HEAD', '--', $path])->stdout;
                if ($entry === '') {
                    $files[$path] = null;

                    continue;
                }
                [$mode, $type, $object] = explode(' ', explode("\t", $entry, 2)[0]);
                $content = $type === 'blob' && $mode !== '120000'
                    ? $this->git($directory, $environment, ['cat-file', 'blob', $object], self::MaximumFileBytes + 1)->stdout : '';
                if (strlen($content) > self::MaximumFileBytes) {
                    throw $this->unavailable();
                }
                $files[$path] = ['mode' => $mode, 'object' => $object, 'content' => $content];
            }

            return ['head' => trim($this->git($directory, $environment, ['rev-parse', 'FETCH_HEAD'])->stdout), 'files' => $files];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @param  array<string,string>  $environment
     * @param  list<string>  $arguments
     */
    private function git(string $directory, array $environment, array $arguments, int $maxOutput = 65_536): CommandResult
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $directory, ...$arguments],
            timeout: 60.0, maxOutputBytes: $maxOutput, environment: $environment,
        ));
        if (! $result->succeeded() || $result->truncated) {
            throw $this->unavailable();
        }

        return $result;
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('project.source_unavailable', 'The Gateway could not read the Project source from its default branch.', 502);
    }
}
