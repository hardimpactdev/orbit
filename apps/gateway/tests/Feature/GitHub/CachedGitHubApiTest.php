<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\GitHub\CachedGitHubApi;
use App\Infrastructure\GitHub\GitHubTokenCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;

describe('cached GitHub credentials of scheduled reads', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::sequence()
                ->push(['token' => 'ghs_first'], 201)
                ->push(['token' => 'ghs_second'], 201)
                ->push(['token' => 'ghs_third'], 201),
        ]);
        $this->repository = GitHubRepository::fromOrigin('https://github.com/acme/shop');
        $this->api = app(CachedGitHubApi::class);
    });

    it('asks GitHub once for the installation and each token until it is forgotten', function (): void {
        $credentials = GitHubTestSupport::credentials();

        $first = $this->api->repositoryReadToken($credentials, (int) $this->api->repositoryInstallation($credentials, $this->repository), $this->repository);
        $again = $this->api->repositoryReadToken($credentials, (int) $this->api->repositoryInstallation($credentials, $this->repository), $this->repository);
        $checks = $this->api->repositoryChecksToken($credentials, 9, $this->repository);

        expect([$first, $again, $checks])->toBe(['ghs_first', 'ghs_first', 'ghs_second']);
        Http::assertSentCount(3);

        app(GitHubTokenCache::class)->forget($this->repository);

        expect($this->api->repositoryReadToken($credentials, (int) $this->api->repositoryInstallation($credentials, $this->repository), $this->repository))->toBe('ghs_third');
        Http::assertSentCount(5);
    });

    it('keeps a token only encrypted and only for the App that minted it', function (): void {
        $credentials = GitHubTestSupport::credentials();
        $this->api->repositoryReadToken($credentials, 9, $this->repository);
        $stored = Cache::get('orbit:github:token:contents:acme/shop');

        expect(json_encode($stored))->not->toContain('ghs_first');

        $other = new GitHubAppCredentials(7, 'other', 'other', 'acme', 'organization', 'https://github.com/apps/other', $credentials->privateKey);

        expect($this->api->repositoryReadToken($other, 9, $this->repository))->toBe('ghs_second');
    });

    it('does not cache a token of another permission', function (): void {
        $credentials = GitHubTestSupport::credentials();
        Http::fake(['https://api.github.com/app/installations/9/access_tokens' => Http::sequence()
            ->push(['token' => 'ghs_write_one'], 201)
            ->push(['token' => 'ghs_write_two'], 201)]);

        expect($this->api->repositoryPullRequestToken($credentials, 9, $this->repository))->not->toBe($this->api->repositoryPullRequestToken($credentials, 9, $this->repository));
    });
});
