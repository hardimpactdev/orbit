<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

function remote_heads_runner(CommandResult $result): ProcessRunner
{
    return new class($result) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function __construct(private CommandResult $result) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return $this->result;
        }
    };
}

describe('reading a branch head from the Gateway', function (): void {
    it('reads any branch with one ls-remote and changes nothing', function (): void {
        $commit = str_repeat('a', 40);
        $runner = remote_heads_runner(new CommandResult(0, "{$commit}\trefs/heads/release/2026\n", '', 1, false));
        $project = new Project(['slug' => 'shop', 'repository_url' => 'https://example.test/shop.git', 'default_branch' => 'main']);

        $head = new GitRemoteBranchHeads($runner, app(RepositoryReadAccess::class))->head($project, 'release/2026');

        expect($head)->toBe($commit)
            ->and($runner->invocations[0]->arguments)->toBe(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', '/', 'ls-remote', '--exit-code', '--heads', '--', 'https://example.test/shop.git', 'refs/heads/release/2026']);
    });

    it('fails closed for a missing branch or another ref and drops the cached GitHub credentials', function (CommandResult $result): void {
        Cache::put('orbit:github:installation:acme/shop', ['app' => 1, 'installation' => 9], 60);
        $project = new Project(['slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
        $heads = new GitRemoteBranchHeads(remote_heads_runner($result), app(RepositoryReadAccess::class), app(GitHubTokenCache::class));

        expect(fn () => $heads->head($project, 'main'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('source.head_unavailable'))
            ->and(Cache::has('orbit:github:installation:acme/shop'))->toBeFalse()
            ->and(GitHubRepository::fromOrigin($project->repository_url))->not->toBeNull();
    })->with([
        'missing branch' => [new CommandResult(2, '', '', 1, false)],
        'another ref' => [new CommandResult(0, str_repeat('a', 40)."\trefs/heads/mainline\n", '', 1, false)],
    ]);

    it('refuses an invalid branch name before it runs git', function (): void {
        $runner = remote_heads_runner(new CommandResult(0, '', '', 1, false));

        expect(fn () => new GitRemoteBranchHeads($runner, app(RepositoryReadAccess::class))->head(new Project(['slug' => 'shop', 'repository_url' => 'https://example.test/shop.git']), '--upload-pack=x'))
            ->toThrow(ResourceOperationException::class)
            ->and($runner->invocations)->toBe([]);
    });
});
