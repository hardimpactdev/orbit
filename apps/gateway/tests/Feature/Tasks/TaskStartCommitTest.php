<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\Tasks\GitHubTaskStartCommit;
use App\Models\Project;
use Tests\Feature\GitHub\GitHubTestSupport;

beforeEach(function (): void {
    GitHubTestSupport::storeApp();
    $this->project = Project::query()->create(['name' => 'Start', 'slug' => 'start', 'type' => 'monorepo', 'repository_url' => 'https://github.com/acme/start.git',
        'default_branch' => 'main', 'source_access' => 'github_app', 'merge_check' => 'Required checks']);
    $this->github = Mockery::mock(GitHubApi::class);
    $this->github->shouldReceive('repositoryInstallation')->andReturn(17);
    app()->instance(GitHubApi::class, $this->github);
});

function start_commit_run(string $sha, ?string $conclusion, string $status = 'completed'): GitHubCheckRun
{
    return new GitHubCheckRun('Required checks', $conclusion, null, status: $status, headSha: $sha, appSlug: 'github-actions');
}

it('pins the newest first-parent commit whose merge check passed, skipping red, pending and merged-side commits', function (): void {
    [$red, $pending, $side, $green, $older] = [str_repeat('1', 40), str_repeat('2', 40), str_repeat('3', 40), str_repeat('4', 40), str_repeat('5', 40)];
    $this->github->shouldReceive('repositoryReadToken')->once()->andReturn('contents-token');
    $this->github->shouldReceive('branchCommits')->once()->with('contents-token', Mockery::any(), 'main')->andReturn([
        new GitHubCommit($red, [$pending]), new GitHubCommit($pending, [$green, $side]), new GitHubCommit($side, [$older]),
        new GitHubCommit($green, [$older]), new GitHubCommit($older, []),
    ]);
    $this->github->shouldReceive('repositoryChecksToken')->once()->andReturn('checks-token');
    $this->github->shouldReceive('checkRuns')->with('checks-token', Mockery::any(), $red, 'Required checks')->once()->andReturn([start_commit_run($red, 'failure')]);
    $this->github->shouldReceive('checkRuns')->with('checks-token', Mockery::any(), $pending, 'Required checks')->once()->andReturn([start_commit_run($pending, null, 'in_progress')]);
    $this->github->shouldReceive('checkRuns')->with('checks-token', Mockery::any(), $green, 'Required checks')->once()->andReturn([start_commit_run($green, 'success')]);
    $this->github->shouldNotReceive('checkRuns')->with(Mockery::any(), Mockery::any(), $side, Mockery::any());

    expect(app(GitHubTaskStartCommit::class)->resolve($this->project))->toBe($green);
});

it('refuses with tasks.no_green_commit when none of the bounded candidates passed', function (): void {
    $commits = array_map(static fn (int $i): GitHubCommit => new GitHubCommit(sprintf('%040x', $i + 1), [sprintf('%040x', $i + 2)]), range(0, 24));
    $this->github->shouldReceive('repositoryReadToken')->once()->andReturn('contents-token');
    $this->github->shouldReceive('branchCommits')->once()->andReturn($commits);
    $this->github->shouldReceive('repositoryChecksToken')->once()->andReturn('checks-token');
    $this->github->shouldReceive('checkRuns')->times(20)->andReturnUsing(fn ($token, $repository, string $sha): array => [start_commit_run($sha, 'failure')]);

    expect(fn () => app(GitHubTaskStartCommit::class)->resolve($this->project))
        ->toThrow(fn (ResourceOperationException $e) => expect([$e->errorCode, $e->status])->toBe(['tasks.no_green_commit', 409]));
});

it('refuses with tasks.source_unavailable when GitHub cannot answer', function (): void {
    $this->github->shouldReceive('repositoryReadToken')->once()->andThrow(new GitHubApiException('upstream secret'));

    expect(fn () => app(GitHubTaskStartCommit::class)->resolve($this->project))
        ->toThrow(fn (ResourceOperationException $e) => expect([$e->errorCode, $e->status, str_contains($e->getMessage(), 'upstream secret')])->toBe(['tasks.source_unavailable', 502, false]));
});

it('refuses with tasks.source_unreadable when the merge check cannot be read through the GitHub App', function (): void {
    $this->project->update(['source_access' => 'gh_cli']);
    $this->github->shouldNotReceive('repositoryReadToken');

    expect(fn () => app(GitHubTaskStartCommit::class)->resolve($this->project))
        ->toThrow(fn (ResourceOperationException $e) => expect([$e->errorCode, $e->status])->toBe(['tasks.source_unreadable', 409]));
});

it('pins the branch head for a Project without a merge check, as automatic deploys do', function (): void {
    $this->project->update(['merge_check' => null]);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->with(Mockery::on(fn (Project $p): bool => $p->is($this->project)), 'main')->andReturn(str_repeat('e', 40));
    app()->instance(RemoteBranchHeads::class, $heads);
    $this->github->shouldNotReceive('repositoryReadToken');

    expect(app(GitHubTaskStartCommit::class)->resolve($this->project))->toBe(str_repeat('e', 40));
});

it('refuses with tasks.source_unavailable when the head of a Project without a merge check is unreadable', function (): void {
    $this->project->update(['merge_check' => null]);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->andThrow(new ResourceOperationException('source.head_unavailable', 'unreadable', 502));
    app()->instance(RemoteBranchHeads::class, $heads);

    expect(fn () => app(GitHubTaskStartCommit::class)->resolve($this->project))
        ->toThrow(fn (ResourceOperationException $e) => expect([$e->errorCode, $e->status])->toBe(['tasks.source_unavailable', 502]));
});
