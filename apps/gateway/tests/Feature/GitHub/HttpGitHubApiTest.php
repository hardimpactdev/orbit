<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

describe('HttpGitHubApi requested reviewers', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('posts the exact requested_reviewers payload', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers' => Http::response([
                'requested_reviewers' => [['login' => 'reviewbot']],
            ], 201),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        app(GitHubApi::class)->requestPullRequestReviewers('ghs_publish', $repository, 11, ['reviewbot']);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers'
            && $request->data() === ['reviewers' => ['reviewbot']]
            && $request->body() === '{"reviewers":["reviewbot"]}'
            && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
        Http::assertSentCount(1);
    });

    it('does not post requested_reviewers when the login list is empty', function (): void {
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        app(GitHubApi::class)->requestPullRequestReviewers('ghs_publish', $repository, 11, []);

        Http::assertNothingSent();
    });

    it('keeps the open pull request payload limited to title, head, base, and body', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls' => Http::response([
                'html_url' => 'https://github.com/acme/shop/pull/11',
                'number' => 11,
                'user' => ['login' => 'orbit-bot'],
            ], 201),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        $opened = app(GitHubApi::class)->openPullRequest('ghs_publish', $repository, new GitHubPullRequestDraft('task-7', 'main', 'Export orders', "Adds the export.\n"));

        expect($opened->url)->toBe('https://github.com/acme/shop/pull/11')
            ->and($opened->number)->toBe(11)
            ->and($opened->authorLogin)->toBe('orbit-bot');
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
            && $request->data() === ['title' => 'Export orders', 'head' => 'task-7', 'base' => 'main', 'body' => "Adds the export.\n"]);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
    });

    it('reuses the open pull request on 422 and still exposes its number', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls' => Http::response(['message' => 'A pull request already exists'], 422),
            'https://api.github.com/repos/acme/shop/pulls?*' => Http::response([[
                'html_url' => 'https://github.com/acme/shop/pull/12',
                'number' => 12,
                'user' => ['login' => 'orbit-bot'],
            ]]),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        $opened = app(GitHubApi::class)->openPullRequest('ghs_publish', $repository, new GitHubPullRequestDraft('task-7', 'main', 'Export orders', 'Body'));

        expect($opened->url)->toBe('https://github.com/acme/shop/pull/12')
            ->and($opened->number)->toBe(12)
            ->and($opened->authorLogin)->toBe('orbit-bot');
    });

    it('names the GitHub refusal when requested_reviewers fails', function (): void {
        Http::fake([
            'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers' => Http::response([
                'message' => 'Review cannot be requested from pull request author.',
            ], 422),
        ]);
        $repository = GitHubRepository::fromOrigin('https://github.com/acme/shop.git');

        expect(fn () => app(GitHubApi::class)->requestPullRequestReviewers('ghs_publish', $repository, 11, ['orbit-bot']))
            ->toThrow(GitHubApiException::class, 'GitHub refused the reviewer request (422): Review cannot be requested from pull request author.');
    });
});

describe('HttpGitHubApi repository id', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('follows the redirect of a transferred repository to its id', function (): void {
        Http::fake([
            'https://api.github.com/repos/old-owner/shop' => Http::response('', 301, ['Location' => 'https://api.github.com/repositories/7']),
            'https://api.github.com/repositories/7' => Http::response(['id' => 7, 'full_name' => 'acme/shop']),
        ]);

        expect(app(GitHubApi::class)->repositoryId('ghs_read', GitHubRepository::fromOrigin('https://github.com/old-owner/shop.git')))->toBe(7);
    });

    it('reads a missing or hidden repository as null', function (int $status): void {
        Http::fake(['https://api.github.com/repos/acme/shop' => Http::response(['message' => 'Not Found'], $status)]);

        expect(app(GitHubApi::class)->repositoryId('ghs_read', GitHubRepository::fromOrigin('https://github.com/acme/shop.git')))->toBeNull();
    })->with(['not found' => [404], 'forbidden' => [403]]);

    it('fails on a rate limit, a server failure, or an answer without an id', function (int $status, array $body, array $headers): void {
        Http::fake(['https://api.github.com/repos/acme/shop' => Http::response($body, $status, $headers)]);

        expect(fn (): ?int => app(GitHubApi::class)->repositoryId('ghs_read', GitHubRepository::fromOrigin('https://github.com/acme/shop.git')))
            ->toThrow(GitHubApiException::class);
    })->with([
        'rate limit' => [403, ['message' => 'API rate limit exceeded'], ['x-ratelimit-remaining' => '0']],
        'server failure' => [502, ['message' => 'Bad gateway'], []],
        'no id' => [200, [], []],
    ]);
});

describe('merged pull request context for production repair', function (): void {
    it('returns only same-repository PRs merged as the deployed commit', function (): void {
        Http::preventStrayRequests();
        $sha = str_repeat('a', 40);
        Http::fake(['https://api.github.com/repos/acme/shop/commits/'.$sha.'/pulls*' => Http::response([
            ['number' => 11, 'html_url' => 'https://github.com/acme/shop/pull/11', 'merged_at' => '2026-10-28T12:00:00Z', 'merge_commit_sha' => $sha],
            ['number' => 12, 'html_url' => 'https://github.com/acme/shop/pull/12', 'merged_at' => null, 'merge_commit_sha' => $sha],
            ['number' => 13, 'html_url' => 'https://github.com/acme/shop/pull/13', 'merged_at' => '2026-10-28T12:00:00Z', 'merge_commit_sha' => str_repeat('b', 40)],
        ])]);
        expect(app(GitHubApi::class)->mergedPullRequestUrls('fixture-read', GitHubRepository::fromOrigin('https://github.com/acme/shop'), $sha))->toBe(['https://github.com/acme/shop/pull/11']);
        Http::assertSentCount(1);
    });

    it('refuses foreign or incomplete associated PR evidence', function (string $kind): void {
        Http::preventStrayRequests();
        $sha = str_repeat('a', 40);
        $row = ['number' => 11, 'html_url' => 'https://github.com/foreign/shop/pull/11', 'merged_at' => '2026-10-28T12:00:00Z', 'merge_commit_sha' => $sha];
        Http::fake(['https://api.github.com/repos/acme/shop/commits/'.$sha.'/pulls*' => Http::response($kind === 'foreign' ? [$row] : array_fill(0, 100, $row))]);
        expect(fn () => app(GitHubApi::class)->mergedPullRequestUrls('fixture-read', GitHubRepository::fromOrigin('https://github.com/acme/shop'), $sha))->toThrow(GitHubApiException::class);
    })->with(['foreign', 'overflow']);
});

it('mints a repository squash token without workflow write authority', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://api.github.com/app/installations/123/access_tokens' => Http::response(['token' => 'fixture-squash'])]);
    $repo = GitHubRepository::fromOrigin('https://github.com/acme/widgets');
    expect(app(GitHubApi::class)->repositorySquashToken(GitHubTestSupport::credentials(), 123, $repo))->toBe('fixture-squash');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['repositories' => ['widgets'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write']]);
});
