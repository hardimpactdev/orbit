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
use Mockery\MockInterface;

uses(LazilyRefreshDatabase::class);

const SOURCE_SHA = 'c0ffee0000000000000000000000000000000002';

/** The source with a stored App and a token minter that names each permission set, so a test sees which token read what. */
function githubSource(): ProofCommitSource
{
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github): void {
        $github->shouldReceive('repositoryInstallation')->andReturn(7);
        $github->shouldReceive('repositoryReadToken')->andReturn('contents-token');
        $github->shouldReceive('repositoryPullRequestReadToken')->andReturn('pulls-token');
        $github->shouldReceive('repositoryMetadataToken')->andReturn('metadata-token');
        $github->shouldReceive('repositoryReviewsToken')->andReturn('reviews-token');
        $github->shouldReceive('reviews')->with('reviews-token', Mockery::any(), 9)->andReturn([]);
        $github->shouldReceive('blobContents')->with('contents-token', Mockery::any(), 'b1')->andReturn('LIST');
    }));
    Http::preventStrayRequests();

    return app(ProofCommitSource::class);
}

function usedToken(int $index = 0): string
{
    return Http::recorded()[$index][0]->header('Authorization')[0];
}

it('says whether a release commit is the merge base or after it', function (): void {
    $base = str_repeat('a', 40);
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/'.$base.'...*' => Http::sequence()
        ->push(['status' => 'ahead', 'merge_base_commit' => ['sha' => $base]])->push(['status' => 'identical', 'merge_base_commit' => ['sha' => $base]])
        ->push(['status' => 'behind', 'merge_base_commit' => ['sha' => str_repeat('c', 40)]])->push(['status' => 'diverged', 'merge_base_commit' => ['sha' => str_repeat('c', 40)]])
        ->push(['message' => 'Not Found'], 404)->push(['message' => 'No commit found'], 422)->push([], 500)]);
    $source = githubSource();

    expect($source->atOrAfter($base, SOURCE_SHA))->toBeTrue()->and($source->atOrAfter($base, $base))->toBeTrue()
        ->and($source->atOrAfter($base, SOURCE_SHA))->toBeFalse()->and($source->atOrAfter($base, SOURCE_SHA))->toBeFalse()
        ->and($source->atOrAfter($base, SOURCE_SHA))->toBeFalse()->and($source->atOrAfter($base, SOURCE_SHA))->toBeFalse()
        ->and(usedToken())->toBe('Bearer contents-token');
    expect(fn () => $source->atOrAfter($base, SOURCE_SHA))->toThrow(GitHubApiException::class);
});
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

it('lists the diff with one request and calls it complete below the 300 files GitHub lists', function (int $count, bool $complete): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/*' => Http::response(['merge_base_commit' => ['sha' => str_repeat('a', 40)],
        'files' => array_map(static fn (int $i): array => ['filename' => "f{$i}", 'status' => 'added'], $count === 0 ? [] : range(1, $count))])]);

    $changes = githubSource()->changes(SOURCE_SHA);

    expect($changes->complete)->toBe($complete)->and($changes->files)->toHaveCount($count);
    Http::assertSentCount(1);
})->with([[0, true], [99, true], [299, true], [300, false]]);

it('refuses a diff with more files than GitHub lists', function (): void {
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/*' => Http::response(['merge_base_commit' => ['sha' => str_repeat('a', 40)],
        'files' => array_map(static fn (int $i): array => ['filename' => "f{$i}", 'status' => 'added'], range(1, 301))])]);

    expect(fn () => githubSource()->changes(SOURCE_SHA))->toThrow(GitHubApiException::class);
});

it('reads a file through the trees of its directories, so a large directory does not hide it', function (): void {
    Http::fake([
        'api.github.com/repos/hardimpactdev/orbit/git/trees/'.SOURCE_SHA => Http::response(['tree' => [['path' => 'swamp', 'type' => 'tree', 'sha' => 's1']]]),
        'api.github.com/repos/hardimpactdev/orbit/git/trees/s1' => Http::response(['tree' => [['path' => 'proof', 'type' => 'tree', 'sha' => 'p1']]]),
        'api.github.com/repos/hardimpactdev/orbit/git/trees/p1' => Http::response(['tree' => [['path' => 'selection.txt', 'type' => 'blob', 'sha' => 'b1'], ['path' => 'other', 'type' => 'tree', 'sha' => 'o1']]]),
    ]);

    expect(githubSource()->file(SOURCE_SHA, 'swamp/proof/selection.txt'))->toBe('LIST')
        ->and(githubSource()->file(SOURCE_SHA, 'swamp/proof/missing.txt'))->toBeNull()
        ->and(githubSource()->file(SOURCE_SHA, 'swamp/other/selection.txt'))->toBeNull();
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
            ['path' => 'firewall', 'type' => 'tree', 'sha' => 'f1'], ['path' => 'firewall/allow.cases.yaml', 'type' => 'blob'], ['path' => 'selection.txt', 'type' => 'blob']]]),
    ]);

    expect(githubSource()->proofPaths(SOURCE_SHA))->toBe(['swamp/proof/firewall/allow.cases.yaml', 'swamp/proof/selection.txt']);
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
