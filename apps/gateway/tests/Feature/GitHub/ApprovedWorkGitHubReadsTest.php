<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Infrastructure\GitHub\HttpGitHubApi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

/** The reads that keep approved work: a branch head that is either there, proven absent, or unread; and a token that may read contents. */
function approvedWorkRepository(): GitHubRepository
{
    return GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit.git') ?? throw new RuntimeException('No repository.');
}

it('reads a branch head, null only when GitHub answers 404, and throws on every other answer', function (): void {
    $sha = str_repeat('c', 40);
    Http::fake([
        'api.github.com/repos/hardimpactdev/orbit/git/ref/heads/task-7' => Http::response(['ref' => 'refs/heads/task-7', 'object' => ['type' => 'commit', 'sha' => $sha]]),
        'api.github.com/repos/hardimpactdev/orbit/git/ref/heads/task-8' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/hardimpactdev/orbit/git/ref/heads/task-9' => Http::response(['message' => 'Server Error'], 502),
        'api.github.com/repos/hardimpactdev/orbit/git/ref/heads/task-10' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);
    $github = app(HttpGitHubApi::class);

    expect($github->branchHead('contents-token', approvedWorkRepository(), 'task-7'))->toBe($sha)
        ->and($github->branchHead('contents-token', approvedWorkRepository(), 'task-8'))->toBeNull()
        ->and(fn () => $github->branchHead('contents-token', approvedWorkRepository(), 'task-9'))->toThrow(GitHubApiException::class)
        ->and(fn () => $github->branchHead('contents-token', approvedWorkRepository(), 'task-10'))->toThrow(GitHubApiException::class);
});

it('mints a contents:read token for this one repository', function (): void {
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]) ?: throw new RuntimeException('No key.'), $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'read', 'read', 'hardimpactdev', 'organization', 'https://github.com/apps/read', $pem));
    app()->instance(GitHubApi::class, app(HttpGitHubApi::class));
    Http::fake([
        'api.github.com/repos/hardimpactdev/orbit/installation' => Http::response(['id' => 7]),
        'api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'ghs_contents'], 201),
    ]);

    expect(app(RepositoryPullRequestAccess::class)->contentsReadToken(approvedWorkRepository()))->toBe('ghs_contents');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['contents' => 'read']]);
});
