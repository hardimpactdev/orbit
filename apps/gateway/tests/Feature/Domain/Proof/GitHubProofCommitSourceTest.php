<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Proof\ProofCommitSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

const SOURCE_SHA = 'c0ffee0000000000000000000000000000000002';

/** The source with a stored App and a token minter that names each permission set, so a test sees which token read what. */
function githubSource(): ProofCommitSource
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (Mockery\MockInterface $github): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-token');
        $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('pulls-token');
        $github->shouldReceive('repositoryMetadataToken')->andReturn('metadata-token');
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('reviews')->with('reviews-token', Mockery::any(), 9)->andReturn([]);
    }));
    Http::preventStrayRequests();

    return app(ProofCommitSource::class);
}

function usedToken(int $index = 0): string
{
    return Http::recorded()[$index][0]->header('Authorization')[0];
}

it('finds the commit and tells an unknown one apart from a GitHub outage', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA => Http::sequence()
        ->push(['sha' => SOURCE_SHA])->push(['message' => 'No commit found'], 422)->push(['message' => 'Not Found'], 404)->push([], 500)]);
    $source = githubSource();

    expect($source->commitExists(SOURCE_SHA))->toBeTrue()->and($source->commitExists(SOURCE_SHA))->toBeFalse()->and($source->commitExists(SOURCE_SHA))->toBeFalse()
        ->and(usedToken())->toBe('Bearer contents-token');
    expect(fn () => $source->commitExists(SOURCE_SHA))->toThrow(GitHubApiException::class);
});

it('lists the branches whose head is the commit', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA.'/branches-where-head' => Http::response([['name' => 'feat/x'], ['name' => 'gh-readonly-queue/main/pr-3']])]);

    expect(githubSource()->branchesWithHead(SOURCE_SHA))->toBe(['feat/x', 'gh-readonly-queue/main/pr-3']);
});

it('reads the pull requests of a commit with the pull request token and keeps their label names', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA.'/pulls*' => Http::response([
        ['number' => 9, 'state' => 'open', 'head' => ['sha' => SOURCE_SHA], 'labels' => [['name' => 'run-proofs'], ['name' => 'other']]],
        ['number' => 4, 'state' => 'closed', 'head' => ['sha' => 'f'.substr(SOURCE_SHA, 1)], 'labels' => []],
    ])]);

    $pullRequests = githubSource()->pullRequestsWithHead(SOURCE_SHA);

    expect($pullRequests)->toHaveCount(2)
        ->and([$pullRequests[0]->number, $pullRequests[0]->open, $pullRequests[0]->headSha, $pullRequests[0]->labels])->toBe([9, true, SOURCE_SHA, ['run-proofs', 'other']])
        ->and($pullRequests[1]->open)->toBeFalse()->and(usedToken())->toBe('Bearer pulls-token');
});

it('reads write access with the metadata token', function (string $body, int $status, bool $writes): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/collaborators/*' => Http::response(json_decode($body, true), $status)]);

    expect(githubSource()->canWrite('someone'))->toBe($writes)->and(usedToken())->toBe('Bearer metadata-token');
})->with([
    'admin' => ['{"permission":"admin","role_name":"admin"}', 200, true],
    'write' => ['{"permission":"write","role_name":"write"}', 200, true],
    'maintain' => ['{"permission":"write","role_name":"maintain"}', 200, true],
    'triage' => ['{"permission":"read","role_name":"triage"}', 200, false],
    'read' => ['{"permission":"read","role_name":"read"}', 200, false],
    'not a collaborator' => ['{"message":"Not Found"}', 404, false],
]);

it('reads the reviews of a pull request through the reviews token', function (): void {
    expect(githubSource()->reviews(9))->toBe([]);
});

it('lists the changed files of the diff against the merge base with main, and keeps a rename old path', function (): void {
    $base = str_repeat('a', 40);
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/main...'.SOURCE_SHA.'*' => Http::response([
        'merge_base_commit' => ['sha' => $base],
        'files' => [['filename' => 'docs/a.md', 'status' => 'modified'], ['filename' => 'new.txt', 'status' => 'renamed', 'previous_filename' => 'old.txt']],
    ])]);

    $changes = githubSource()->changes(SOURCE_SHA);

    expect($changes->mergeBase)->toBe($base)->and($changes->complete)->toBeTrue()->and($changes->paths())->toBe(['docs/a.md', 'new.txt', 'old.txt']);
});

it('reads every page of a large diff, and calls the list incomplete when GitHub stops at its limit', function (): void {
    $page = static fn (int $number): array => ['merge_base_commit' => ['sha' => str_repeat('a', 40)],
        'files' => array_map(static fn (int $i): array => ['filename' => "f{$number}-{$i}", 'status' => 'added'], range(1, 100))];
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/*' => Http::response($page(1))]);

    $changes = githubSource()->changes(SOURCE_SHA);

    // The same full page answers every request, so the walk ends at its 30 page limit with a full last page.
    expect($changes->complete)->toBeFalse()->and($changes->files)->toHaveCount(3000);
    Http::assertSentCount(30);
});

it('reads the diff of a small commit in one request and finds it complete', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/*' => Http::response(['merge_base_commit' => ['sha' => str_repeat('a', 40)],
        'files' => array_map(static fn (int $i): array => ['filename' => "f{$i}", 'status' => 'added'], range(1, 99))])]);

    expect(githubSource()->changes(SOURCE_SHA)->complete)->toBeTrue();
    Http::assertSentCount(1);
});

it('refuses a diff that GitHub answers without a merge base', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/*' => Http::response(['files' => []])]);

    expect(fn () => githubSource()->changes(SOURCE_SHA))->toThrow(GitHubApiException::class);
});

it('walks swamp then proof and lists the blobs of the proof tree', function (): void {
    Http::fake([
        'api.github.com/repos/hardimpactdev/orbit/git/trees/'.SOURCE_SHA => Http::response(['tree' => [['path' => 'apps', 'type' => 'tree', 'sha' => 'a1'], ['path' => 'swamp', 'type' => 'tree', 'sha' => 's1']]]),
        'api.github.com/repos/hardimpactdev/orbit/git/trees/s1' => Http::response(['tree' => [['path' => 'proof', 'type' => 'tree', 'sha' => 'p1'], ['path' => 'bin', 'type' => 'tree', 'sha' => 'b1']]]),
        'api.github.com/repos/hardimpactdev/orbit/git/trees/p1*' => Http::response(['truncated' => false, 'tree' => [
            ['path' => 'firewall', 'type' => 'tree', 'sha' => 'f1'], ['path' => 'firewall/cases/allow-a.sh', 'type' => 'blob'], ['path' => 'selection.txt', 'type' => 'blob']]]),
    ]);

    expect(githubSource()->proofPaths(SOURCE_SHA))->toBe(['swamp/proof/firewall/cases/allow-a.sh', 'swamp/proof/selection.txt']);
});

it('refuses a truncated proof tree, because a missing case would pass unproven', function (): void {
    Http::fake([
        'api.github.com/repos/hardimpactdev/orbit/git/trees/'.SOURCE_SHA => Http::response(['tree' => [['path' => 'swamp', 'type' => 'tree', 'sha' => 's1']]]),
        'api.github.com/repos/hardimpactdev/orbit/git/trees/s1' => Http::response(['tree' => [['path' => 'proof', 'type' => 'tree', 'sha' => 'p1']]]),
        'api.github.com/repos/hardimpactdev/orbit/git/trees/p1*' => Http::response(['truncated' => true, 'tree' => []]),
    ]);

    expect(fn () => githubSource()->proofPaths(SOURCE_SHA))->toThrow(GitHubApiException::class);
});

it('has no proof paths when the commit has no swamp/proof directory', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/git/trees/'.SOURCE_SHA => Http::response(['tree' => [['path' => 'apps', 'type' => 'tree', 'sha' => 'a1']]])]);

    expect(githubSource()->proofPaths(SOURCE_SHA))->toBe([]);
});

it('sends every request to the configured repository with a read token and no body', function (): void {
    Http::fake(['api.github.com/*' => Http::response([['name' => 'main']])]);

    githubSource()->branchesWithHead(SOURCE_SHA);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_starts_with($request->url(), 'https://api.github.com/repos/hardimpactdev/orbit/'));
});
