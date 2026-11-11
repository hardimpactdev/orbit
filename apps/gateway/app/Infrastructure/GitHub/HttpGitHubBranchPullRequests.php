<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubBranchPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/** A bounded repository read, separate from the existing one-page, all-state head reader. */
final readonly class HttpGitHubBranchPullRequests
{
    public const int PageLimit = 5;

    /** @return array<string, GitHubBranchPullRequest> Newest open PR for each head in this repository. */
    public function newestOpen(#[SensitiveParameter] string $token, GitHubRepository $repository): array
    {
        $deadline = microtime(true) + 10.0;
        $pulls = [];
        for ($page = 1; $page <= self::PageLimit; $page++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw GitHubApiException::unavailable();
            }
            $response = Http::baseUrl('https://api.github.com')->withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'orbit-gateway'])
                ->timeout(min(5.0, $remaining))->connectTimeout(min(2.0, $remaining))->withoutRedirecting()
                ->get('/repos/'.$repository->owner.'/'.$repository->name.'/pulls', [
                    'state' => 'open', 'sort' => 'created', 'direction' => 'desc', 'per_page' => 100, 'page' => $page,
                ]);
            $rows = $response->json();
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 100 || microtime(true) >= $deadline) {
                throw GitHubApiException::unavailable();
            }
            foreach ($rows as $row) {
                if (! is_array($row) || ! in_array($row['state'] ?? null, ['open', 'closed'], true)) {
                    throw GitHubApiException::unavailable();
                }
                if ($row['state'] === 'closed') {
                    continue;
                }
                $number = $row['number'] ?? null;
                $url = $row['html_url'] ?? null;
                $title = $row['title'] ?? null;
                $created = $row['created_at'] ?? null;
                $headData = is_array($row['head'] ?? null) ? $row['head'] : [];
                $headRepository = is_array($headData['repo'] ?? null) ? $headData['repo'] : [];
                $head = $headData['ref'] ?? null;
                $source = $headRepository['full_name'] ?? null;
                if (! is_int($number) || ! is_string($url) || $repository->pullRequestNumber($url) !== $number
                    || ! is_string($title) || $title === '' || ! is_string($head) || $head === ''
                    || ! is_string($created) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $created) !== 1) {
                    throw GitHubApiException::unavailable();
                }
                if (! is_string($source) || strcasecmp($source, $repository->owner.'/'.$repository->name) !== 0) {
                    continue;
                }
                $previous = $pulls[$head] ?? null;
                if ($previous === null || strcmp($created, $previous['created']) > 0
                    || ($created === $previous['created'] && $number > $previous['pull']->number)) {
                    $pulls[$head] = ['created' => $created, 'pull' => new GitHubBranchPullRequest($url, $number, GitHubPullRequestState::Open, $title)];
                }
            }
            if (! str_contains($response->header('Link'), 'rel="next"')) {
                return array_map(static fn (array $entry): GitHubBranchPullRequest => $entry['pull'], $pulls);
            }
        }

        throw GitHubApiException::unavailable();
    }
}
