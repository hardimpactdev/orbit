<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubReadFailureKind;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofCommitTrust;
use App\Domain\Proof\ProofGitHubRefusal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
    Sleep::fake();

    return app(ProofCommitSource::class);
}

function usedToken(int $index = 0): string
{
    return Http::recorded()[$index][0]->header('Authorization')[0];
}

it('says how one commit stands to another and which files differ between them', function (): void {
    $from = str_repeat('a', 40);
    $files = [['filename' => 'docs/a.md', 'status' => 'modified'], ['filename' => 'swamp/ansible/new.yml', 'status' => 'renamed', 'previous_filename' => 'swamp/ansible/old.yml']];
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/'.$from.'...*' => Http::sequence()
        ->push(['status' => 'ahead', 'merge_base_commit' => ['sha' => $from], 'files' => $files])
        ->push(['status' => 'identical', 'merge_base_commit' => ['sha' => $from], 'files' => []])
        ->push(['status' => 'behind', 'merge_base_commit' => ['sha' => SOURCE_SHA], 'files' => []])
        ->push(['status' => 'diverged', 'merge_base_commit' => ['sha' => str_repeat('c', 40)], 'files' => []])
        ->push(['status' => 'ahead', 'merge_base_commit' => ['sha' => $from], 'files' => array_fill(0, 300, ['filename' => 'docs/a.md', 'status' => 'modified'])])
        ->push(['message' => 'Not Found'], 404)->push(['message' => 'No commit found'], 422)->push([], 500)]);
    $source = githubSource();

    $ahead = $source->range($from, SOURCE_SHA);
    expect($ahead->status)->toBe(GitHubComparisonStatus::Ahead)->and($ahead->changes->paths())->toBe(['docs/a.md', 'swamp/ansible/new.yml', 'swamp/ansible/old.yml'])->and($ahead->changes->complete)->toBeTrue()
        ->and($source->range($from, SOURCE_SHA)->status)->toBe(GitHubComparisonStatus::Identical)
        ->and($source->range($from, SOURCE_SHA)->status)->toBe(GitHubComparisonStatus::Behind)
        ->and($source->range($from, SOURCE_SHA)->status)->toBe(GitHubComparisonStatus::Diverged)
        // GitHub lists 300 files at most and gives no total, so a full list may be cut.
        ->and($source->range($from, SOURCE_SHA)->changes->complete)->toBeFalse()
        ->and($source->range($from, SOURCE_SHA))->toBeNull()->and($source->range($from, SOURCE_SHA))->toBeNull()
        ->and(usedToken())->toBe('Bearer contents-token');
    expect(fn () => $source->range($from, SOURCE_SHA))->toThrow(GitHubApiException::class);
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

it('reads only an intact runner artifact for the exact CI head and comparison', function (string $failure): void {
    $source = githubSource();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
    $base = str_repeat('a', 40);
    $result = ['version' => 1, 'base' => $failure === 'base' ? 'stale' : $base, 'head' => SOURCE_SHA, 'files' => []];
    $path = sys_get_temp_dir().'/proof-artifact-'.bin2hex(random_bytes(8)).'.zip';
    try {
        $zip = new PharData($path, 0, null, Phar::ZIP);
        $zip['proof-selection.json'] = json_encode($result, JSON_THROW_ON_ERROR);
        unset($zip);
        $archive = file_get_contents($path);
    } finally {
        unlink($path);
    }
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/installation') => Http::response(['id' => 7]),
        str_ends_with($request->url(), '/access_tokens') => Http::response(['token' => 'actions-token', 'permissions' => ['actions' => 'read']], 201),
        str_contains($request->url(), '/actions/artifacts?') => Http::response(['artifacts' => $failure === 'missing' ? [] : [[
            'id' => 99, 'name' => 'proof-selection-'.SOURCE_SHA, 'expired' => $failure === 'expired',
            'digest' => 'sha256:'.($failure === 'digest' ? str_repeat('0', 64) : hash('sha256', $archive)),
            'workflow_run' => ['id' => 77, 'head_sha' => SOURCE_SHA],
        ]]]),
        str_ends_with($request->url(), '/actions/runs/77') => Http::response([
            'path' => $failure === 'workflow' ? '.github/workflows/other.yml' : '.github/workflows/ci.yml',
            'head_sha' => $failure === 'head' ? 'stale' : SOURCE_SHA, 'event' => 'pull_request',
            'repository' => ['full_name' => 'hardimpactdev/orbit'],
        ]),
        str_ends_with($request->url(), '/actions/artifacts/99/zip') => Http::response('', 302, ['Location' => 'https://test.blob.core.windows.net/artifact']),
        $request->url() === 'https://test.blob.core.windows.net/artifact' => Http::response($archive),
        default => Http::response('', 500),
    });
    expect($source->caseSelection($base, SOURCE_SHA))->toBe($failure === 'none' ? $result : null);
    Http::assertSent(static fn (Request $request): bool => ! str_contains($request->url(), 'blob.core.windows.net') || ! $request->hasHeader('Authorization'));
})->with(['none', 'base', 'head', 'digest', 'workflow', 'expired', 'missing']);

it('reads all history pages and compares every commit to its own first parent', function (): void {
    $base = str_repeat('a', 40);
    $head = str_repeat('b', 40);
    $rows = [];
    $parents = [];
    $parent = $base;
    for ($i = 1; $i <= 101; $i++) {
        $sha = $i === 101 ? $head : str_pad(dechex($i), 40, '0', STR_PAD_LEFT);
        $rows[] = ['sha' => $sha, 'parents' => [['sha' => $parent]]];
        $parents[$sha] = $parent;
        $parent = $sha;
    }
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/*' => function (Request $request) use ($base, $head, $rows, $parents) {
        if ($request['per_page'] === 100) {
            expect($request->url())->toContain($base.'...'.$head);

            return Http::response(['status' => 'ahead', 'merge_base_commit' => ['sha' => $base], 'total_commits' => 101,
                'commits' => array_slice($rows, ($request['page'] - 1) * 100, 100), 'files' => []]);
        }
        $sha = explode('?', explode('...', $request->url())[1])[0];
        expect($request->url())->toContain($parents[$sha].'...'.$sha);

        return Http::response(['status' => 'ahead', 'merge_base_commit' => ['sha' => $parents[$sha]], 'files' => [['filename' => 'changed-and-reverted.py', 'status' => 'modified']]]);
    }]);

    $history = githubSource()->history($base, $head);

    expect($history)->toHaveCount(101);
    expect($history[$head]->mergeBase)->toBe($parents[$head]);
    expect($history[$head]->paths())->toBe(['changed-and-reverted.py']);
    Http::assertSentCount(103);
});

it('refuses incomplete or malformed history rather than dropping a merged commit', function (string $fault): void {
    $base = str_repeat('a', 40);
    $head = SOURCE_SHA;
    $body = ['status' => 'ahead', 'merge_base_commit' => ['sha' => $base], 'total_commits' => 2,
        'commits' => [['sha' => str_repeat('c', 40), 'parents' => [['sha' => $base]]], ['sha' => $head, 'parents' => [['sha' => str_repeat('c', 40)]]]]];
    match ($fault) {
        'truncated' => array_pop($body['commits']),
        'duplicate' => $body['commits'][1] = $body['commits'][0],
        'no parent' => $body['commits'][1]['parents'] = [],
        'missing head' => $body['commits'][1]['sha'] = str_repeat('d', 40),
        'missing count' => $body['total_commits'] = null,
    };
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/'.$base.'...'.$head.'*' => Http::response($body)]);

    expect(fn () => githubSource()->history($base, $head))->toThrow(GitHubApiException::class);
})->with(['truncated', 'duplicate', 'no parent', 'missing head', 'missing count']);

it('distinguishes an empty range from a nonancestor or missing base', function (string $status, int $http, ?array $expected): void {
    $base = str_repeat('a', 40);
    Http::fake(['api.github.com/repos/hardimpactdev/orbit/compare/'.$base.'...'.$base.'*' => Http::response([
        'status' => $status, 'merge_base_commit' => ['sha' => $base], 'total_commits' => 0, 'commits' => [],
    ], $http)]);

    expect(githubSource()->history($base, $base))->toBe($expected);
})->with([['identical', 200, []], ['diverged', 200, null], ['behind', 200, null], ['missing', 404, null]]);

/** The failure of one read of the branches of the commit, after the source spent what it may on it. */
function failedBranchRead(): GitHubApiException
{
    try {
        githubSource()->branchesWithHead(SOURCE_SHA);
    } catch (GitHubApiException $exception) {
        return $exception;
    }
    throw new LogicException('Expected a failed read.');
}

describe('a failed GitHub read keeps its reason', function (): void {
    it('names the read, the status or transport error and a rate-limit reset, and retries only a transient failure', function (Closure $answer, GitHubReadFailureKind $kind, string $reason, int $requests): void {
        CarbonImmutable::setTestNow('2026-11-15T05:02:14Z');
        $sent = 0;
        Http::fake(['api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA.'/branches-where-head' => function () use ($answer, &$sent) {
            $sent++;

            return $answer();
        }]);

        $exception = failedBranchRead();

        expect($exception->readFailure?->kind)->toBe($kind)->and($exception->getMessage())->toBe($reason)->and($sent)->toBe($requests);
        $requests === 2 ? Sleep::assertSequence([Sleep::for(2)->seconds()]) : Sleep::assertNeverSlept();
    })->with([
        'a 5xx, twice' => [fn () => Http::response(['message' => 'Server Error'], 503), GitHubReadFailureKind::Transient, 'The commit branches read answered HTTP 503.', 2],
        'a transport error, twice' => [fn () => throw new ConnectionException('cURL error 28: timed out'), GitHubReadFailureKind::Transient, 'The commit branches read failed with ConnectionException.', 2],
        'the primary rate limit' => [fn () => Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1794722400']),
            GitHubReadFailureKind::RateLimited, 'The commit branches read hit the GitHub rate limit (HTTP 403), which resets at 2026-11-15T06:00:00+00:00.', 1],
        'a secondary rate limit' => [fn () => Http::response(['message' => 'You have exceeded a secondary rate limit'], 429, ['Retry-After' => '60']),
            GitHubReadFailureKind::RateLimited, 'The commit branches read hit the GitHub rate limit (HTTP 429), which resets at 2026-11-15T05:03:14+00:00.', 1],
        'a secondary rate limit without headers' => [fn () => Http::response(['message' => 'You have exceeded a secondary rate limit. Please wait a few minutes.'], 403),
            GitHubReadFailureKind::RateLimited, 'The commit branches read hit the GitHub rate limit (HTTP 403), and GitHub gave no reset time.', 1],
        'a 401' => [fn () => Http::response(['message' => 'Bad credentials'], 401), GitHubReadFailureKind::Refused, 'The commit branches read answered HTTP 401.', 1],
        'a 403 that is not a rate limit' => [fn () => Http::response(['message' => 'Resource not accessible by integration'], 403, ['X-RateLimit-Remaining' => '4999']),
            GitHubReadFailureKind::Refused, 'The commit branches read answered HTTP 403.', 1],
        'a 404' => [fn () => Http::response(['message' => 'Not Found'], 404), GitHubReadFailureKind::NotFound, 'The commit branches read answered HTTP 404.', 1],
    ]);

    it('reads once more after a transient failure and goes on with the answer', function (): void {
        Http::fake(['api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA.'/branches-where-head' => Http::sequence()
            ->push(['message' => 'Bad Gateway'], 502)->push([['name' => 'main']])]);

        expect(githubSource()->branchesWithHead(SOURCE_SHA))->toBe(['main'])->and(Http::recorded())->toHaveCount(2);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    });

    it('retries once for the whole admission, so a long outage adds one pause and one read timeout at most', function (): void {
        Http::fake(['api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA.'/branches-where-head' => Http::sequence()
            ->push([], 500)->push([['name' => 'main']])->push([], 500)]);
        $source = githubSource();

        expect($source->branchesWithHead(SOURCE_SHA))->toBe(['main'])
            ->and(fn () => $source->branchesWithHead(SOURCE_SHA))->toThrow(GitHubApiException::class, 'The commit branches read answered HTTP 500.')
            ->and(Http::recorded())->toHaveCount(3);
        Sleep::assertSleptTimes(1);
    });

    it('admits a commit whose first trust read failed for a moment', function (): void {
        Http::fake([
            'api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA => Http::sequence()->push([], 503)->push(['sha' => SOURCE_SHA]),
            'api.github.com/repos/hardimpactdev/orbit/commits/'.SOURCE_SHA.'/branches-where-head' => Http::response([['name' => 'main']]),
        ]);

        app(ProofCommitTrust::class, ['source' => githubSource()])->assert(SOURCE_SHA);

        expect(Http::recorded())->toHaveCount(3);
    });

    it('shows no token, Authorization header or URL in any refusal', function (Closure $answer): void {
        Http::fake(['api.github.com/*' => $answer]);

        $refusal = ProofGitHubRefusal::from(failedBranchRead(), 'the commit cannot be checked');

        foreach (['contents-token', 'Authorization', 'Bearer', 'https://', 'api.github.com', '/repos/'] as $secret) {
            expect($refusal->getMessage())->not->toContain($secret);
        }
    })->with([
        'a transport error that quotes the request' => [fn (Request $request) => throw new ConnectionException('cURL error 7 for '.$request->url().' with Authorization: '.$request->header('Authorization')[0])],
        'a refusal that echoes the token' => [fn (Request $request) => Http::response(['message' => 'Bad credentials '.$request->header('Authorization')[0]], 401)],
        'a rate limit' => [fn () => Http::response([], 429, ['Retry-After' => '5'])],
    ]);
});
