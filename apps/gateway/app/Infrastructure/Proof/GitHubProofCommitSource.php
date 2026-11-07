<?php

declare(strict_types=1);

namespace App\Infrastructure\Proof;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewOverflowException;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofCommitRange;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofPullRequest;
use App\Domain\Proof\ProofSettings;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/** Reads the proof repository through the Gateway GitHub App. Every read is a GitHub API call; no code is fetched. One instance per request, so each token is minted once. */
#[Scoped]
final class GitHubProofCommitSource implements ProofCommitSource
{
    private const string BASE_URL = 'https://api.github.com';

    private const int PAGE_SIZE = 100;

    /** GitHub lists at most 300 files of a comparison, on its first page. */
    private const int COMPARE_FILES = 300;

    /** @var array<string,string> One token per permission set, for the life of this request. */
    private array $tokens = [];

    public function __construct(private readonly GitHubAppStore $store, private readonly GitHubApi $github, private readonly ProofSettings $settings) {}

    public function commitExists(string $sha): bool
    {
        $response = $this->get($this->contentsToken(), $this->path('/commits/'.$sha));
        if (in_array($response->status(), [404, 422], true)) {
            return false;
        }
        $this->assertOk($response);

        return $response->json('sha') === $sha;
    }

    public function branchesWithHead(string $sha): array
    {
        $rows = $this->rows($this->get($this->contentsToken(), $this->path('/commits/'.$sha.'/branches-where-head')));

        return array_values(array_filter(array_map(static fn (mixed $row): ?string => is_array($row) && is_string($row['name'] ?? null) ? $row['name'] : null, $rows)));
    }

    public function pullRequestsWithHead(string $sha): array
    {
        $rows = $this->rows($this->get($this->token('pulls', fn (GitHubAppCredentials $c, int $i, GitHubRepository $r): string => $this->github->repositoryPullRequestReadToken($c, $i, $r)),
            $this->path('/commits/'.$sha.'/pulls'), ['per_page' => self::PAGE_SIZE]));
        $pullRequests = [];
        foreach ($rows as $row) {
            $number = is_array($row) ? ($row['number'] ?? null) : null;
            $head = is_array($row) && is_array($row['head'] ?? null) ? ($row['head']['sha'] ?? null) : null;
            if (! is_int($number) || ! is_string($head) || ! is_string($row['state'] ?? null) || ! is_array($row['labels'] ?? null)) {
                throw GitHubApiException::unavailable();
            }
            $labels = array_values(array_filter(array_map(static fn (mixed $label): ?string => is_array($label) && is_string($label['name'] ?? null) ? $label['name'] : null, $row['labels'])));
            $pullRequests[] = new ProofPullRequest($number, $row['state'] === 'open', $head, $labels);
        }

        return $pullRequests;
    }

    public function canWrite(string $login): bool
    {
        $response = $this->get($this->token('metadata', fn (GitHubAppCredentials $c, int $i, GitHubRepository $r): string => $this->github->repositoryMetadataToken($c, $i, $r)),
            $this->path('/collaborators/'.rawurlencode($login).'/permission'));
        if ($response->status() === 404) {
            return false;
        }
        $this->assertOk($response);

        return in_array($response->json('permission'), ['admin', 'write'], true) || in_array($response->json('role_name'), ['admin', 'maintain', 'write'], true);
    }

    public function reviews(int $pullRequest): array
    {
        try {
            return $this->github->reviews($this->token('reviews', fn (GitHubAppCredentials $c, int $i, GitHubRepository $r): string => $this->github->repositoryReviewsToken($c, $i, $r)),
                $this->settings->repository(), $pullRequest);
        } catch (GitHubReviewOverflowException) {
            throw GitHubApiException::unavailable();
        }
    }

    public function changes(string $sha): ProofChangedFiles
    {
        $response = $this->get($this->contentsToken(), $this->path('/compare/'.rawurlencode($this->settings->baseBranch()).'...'.$sha), ['per_page' => 1]);
        $this->assertOk($response);
        $mergeBase = $response->json('merge_base_commit.sha');
        $rows = $response->json('files') ?? [];
        if (! is_string($mergeBase) || preg_match('/\A[0-9a-f]{40}\z/D', $mergeBase) !== 1 || ! is_array($rows) || ! array_is_list($rows) || count($rows) > self::COMPARE_FILES) {
            throw GitHubApiException::unavailable();
        }

        // GitHub lists no more than 300 files of a comparison and gives no total, so a full list may be cut.
        return new ProofChangedFiles($mergeBase, $this->changedFiles($rows), count($rows) < self::COMPARE_FILES);
    }

    /**
     * @param  array<int, mixed>  $rows  the `files` of a comparison
     * @return list<ProofChangedFile>
     */
    private function changedFiles(array $rows): array
    {
        $files = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['filename'] ?? null) || ! is_string($row['status'] ?? null)) {
                throw GitHubApiException::unavailable();
            }
            $files[] = new ProofChangedFile($row['filename'], $row['status'], is_string($row['previous_filename'] ?? null) ? $row['previous_filename'] : null);
        }

        return $files;
    }

    public function range(string $from, string $to): ?ProofCommitRange
    {
        $response = $this->get($this->contentsToken(), $this->path('/compare/'.$from.'...'.$to), ['per_page' => 1]);
        if (in_array($response->status(), [404, 422], true)) {
            return null;
        }
        $this->assertOk($response);
        $status = GitHubComparisonStatus::tryFrom(is_string($response->json('status')) ? $response->json('status') : '');
        $mergeBase = $response->json('merge_base_commit.sha');
        $rows = $response->json('files') ?? [];
        if ($status === null || ! is_string($mergeBase) || ! is_array($rows) || ! array_is_list($rows) || count($rows) > self::COMPARE_FILES) {
            throw GitHubApiException::unavailable();
        }

        return new ProofCommitRange($status, new ProofChangedFiles($mergeBase, $this->changedFiles($rows), count($rows) < self::COMPARE_FILES));
    }

    public function file(string $sha, string $path): ?string
    {
        $segments = explode('/', $path);
        $name = array_pop($segments);
        $tree = $sha;
        foreach ($segments as $segment) {
            $tree = $this->child($tree, $segment, 'tree');
            if ($tree === null) {
                return null;
            }
        }
        $blob = $this->child($tree, $name, 'blob');

        return $blob === null ? null : $this->github->blobContents($this->contentsToken(), $this->settings->repository(), $blob);
    }

    public function proofPaths(string $sha): array
    {
        $tree = $this->child($sha, 'swamp', 'tree');
        $tree = $tree === null ? null : $this->child($tree, 'proof', 'tree');
        if ($tree === null) {
            return [];
        }
        $listing = $this->tree($tree, recursive: true);
        if ($listing['truncated']) {
            throw GitHubApiException::unavailable();
        }

        return array_values(array_map(static fn (array $entry): string => 'swamp/proof/'.$entry['path'],
            array_filter($listing['tree'], static fn (array $entry): bool => ($entry['type'] ?? null) === 'blob' && is_string($entry['path'] ?? null))));
    }

    /** The git SHA of one named entry of a tree, or null when the tree has none of that type. A cut listing fails. */
    private function child(string $tree, string $name, string $type): ?string
    {
        $listing = $this->tree($tree);
        if ($listing['truncated']) {
            throw GitHubApiException::unavailable();
        }
        foreach ($listing['tree'] as $entry) {
            if (($entry['path'] ?? null) === $name && ($entry['type'] ?? null) === $type && is_string($entry['sha'] ?? null)) {
                return $entry['sha'];
            }
        }

        return null;
    }

    /** @return array{tree: list<array<mixed>>, truncated: bool} */
    private function tree(string $sha, bool $recursive = false): array
    {
        $response = $this->get($this->contentsToken(), $this->path('/git/trees/'.$sha), $recursive ? ['recursive' => '1'] : []);
        $this->assertOk($response);
        $tree = $response->json('tree');
        if (! is_array($tree) || ! array_is_list($tree)) {
            throw GitHubApiException::unavailable();
        }

        return ['tree' => array_values(array_filter($tree, is_array(...))), 'truncated' => $response->json('truncated') === true];
    }

    private function contentsToken(): string
    {
        return $this->token('contents', fn (GitHubAppCredentials $c, int $i, GitHubRepository $r): string => $this->github->repositoryReadToken($c, $i, $r));
    }

    /** @param callable(GitHubAppCredentials, int, GitHubRepository): string $mint */
    private function token(string $kind, callable $mint): string
    {
        if (isset($this->tokens[$kind])) {
            return $this->tokens[$kind];
        }
        $credentials = $this->store->credentials() ?? throw new GitHubApiException('The Gateway GitHub App is not registered.');
        $repository = $this->settings->repository();
        $installation = $this->github->repositoryInstallation($credentials, $repository)
            ?? throw new GitHubApiException('The Gateway GitHub App is not installed on '.$repository->owner.'/'.$repository->name.'.');

        return $this->tokens[$kind] = $mint($credentials, $installation, $repository);
    }

    private function path(string $suffix): string
    {
        $repository = $this->settings->repository();

        return '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name).$suffix;
    }

    /** @param array<string,mixed> $query */
    private function get(#[SensitiveParameter] string $token, string $path, array $query = []): Response
    {
        try {
            return Http::baseUrl(self::BASE_URL)->timeout(10)->withoutRedirecting()->withToken($token)->withHeaders([
                'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'orbit-gateway',
            ])->get($path, $query);
        } catch (Throwable) {
            throw GitHubApiException::unavailable();
        }
    }

    private function assertOk(Response $response): void
    {
        if ($response->status() !== 200) {
            throw GitHubApiException::unavailable();
        }
    }

    /** @return list<mixed> */
    private function rows(Response $response): array
    {
        $this->assertOk($response);
        $rows = $response->json();

        return is_array($rows) && array_is_list($rows) ? $rows : throw GitHubApiException::unavailable();
    }
}
