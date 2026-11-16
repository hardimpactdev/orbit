<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandDeadline;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Reads GitHub Actions workflows, runs, jobs and artifacts of one repository with an installation token of the
 * Gateway GitHub App that holds only Actions read, and workflow files with one that holds only Contents read.
 * Artifact archives are downloaded from the
 * storage URL GitHub redirects to, without the token. Every failure is a
 * {@see GitHubActionsUnavailable} without detail, so no token or signed URL reaches an error.
 */
final readonly class GitHubActionsReader
{
    public const string Api = 'https://api.github.com';

    /** Responses of the JSON endpoints are read up to this size. */
    private const int JsonBytes = 1024 * 1024;

    /** A page of 100 runs or jobs carries full objects and can pass {@see self::JsonBytes}. */
    private const int ListBytes = 8 * 1024 * 1024;

    /** Runs and jobs per page, GitHub's largest page. */
    private const int PerPage = 100;

    public function __construct(private GitHubAppStore $apps, private CommandDeadline $deadline) {}

    /** The API path of a repository, `/repos/<owner>/<name>`. */
    public static function repositoryPath(GitHubRepository $repository): string
    {
        return '/repos/'.rawurlencode($repository->owner).'/'.rawurlencode($repository->name);
    }

    /** A separate token that holds only one read permission, Actions read unless another is named. */
    public function token(GitHubRepository $repository, string $permission = 'actions'): string
    {
        $credentials = $this->apps->credentials();

        if ($credentials === null) {
            throw new GitHubActionsUnavailable(404);
        }

        try {
            $jwt = GitHubAppJwt::sign($credentials, now()->getTimestamp());
        } catch (GitHubApiException) {
            throw new GitHubActionsUnavailable;
        }

        $installation = $this->json(self::repositoryPath($repository).'/installation', $jwt);
        $id = $installation['id'] ?? null;

        if (! is_int($id) || $id < 1) {
            throw new GitHubActionsUnavailable;
        }

        $access = $this->json('/app/installations/'.$id.'/access_tokens', $jwt, [
            'repositories' => [$repository->name], 'permissions' => [$permission => 'read'],
        ]);
        $token = $access['token'] ?? null;
        $permissions = $access['permissions'] ?? null;

        if (! is_string($token) || $token === '' || ! is_array($permissions)
            || ($permissions[$permission] ?? null) !== 'read') {
            throw new GitHubActionsUnavailable;
        }

        return $token;
    }

    /**
     * One JSON object from an API path such as `/repos/acme/shop/actions/runs/9`.
     *
     * @param  array<string, mixed>|null  $payload  Sent as a POST body when given.
     * @return array<array-key, mixed>
     *
     * @throws GitHubActionsUnavailable
     */
    public function json(string $path, #[SensitiveParameter] string $token, ?array $payload = null, int $limit = self::JsonBytes): array
    {
        $data = json_decode($this->body(self::Api.$path, $token, $limit, $payload), true);

        if (! is_array($data)) {
            throw new GitHubActionsUnavailable(invalid: true);
        }

        return $data;
    }

    /** GitHub gives at most this many runs for one run list query that filters by `created`. */
    public const int RunQueryLimit = 1000;

    /**
     * The workflows of the repository: each one's `id` and `path`.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws GitHubActionsUnavailable
     */
    public function workflows(GitHubRepository $repository, #[SensitiveParameter] string $token): array
    {
        return $this->pages(self::repositoryPath($repository).'/actions/workflows', [], 'workflows', $token)[0];
    }

    /**
     * The text of a file on the default branch, read with a `contents: read` token, or null when the branch has no such file or
     * GitHub does not give its content, as for a file over 1 MB.
     *
     * @throws GitHubActionsUnavailable
     */
    public function file(GitHubRepository $repository, #[SensitiveParameter] string $token, string $path): ?string
    {
        try {
            $data = $this->json(self::repositoryPath($repository).'/contents/'.implode('/', array_map(rawurlencode(...), explode('/', $path))), $token);
        } catch (GitHubActionsUnavailable $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        if (($data['encoding'] ?? null) !== 'base64') {
            return null;
        }

        $text = is_string($data['content'] ?? null) ? base64_decode($data['content'], true) : false;

        if (! is_string($text)) {
            throw new GitHubActionsUnavailable(invalid: true);
        }

        return $text;
    }

    /**
     * Every run of one workflow created from `$since` to `$until`, newest first. GitHub answers a query that filters by `created`
     * with at most {@see self::RunQueryLimit} runs, so a longer window is read in halves until each half fits.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws GitHubActionsUnavailable
     */
    public function workflowRuns(GitHubRepository $repository, #[SensitiveParameter] string $token, int $workflowId, DateTimeInterface $since, DateTimeInterface $until): array
    {
        $utc = new DateTimeZone('UTC');
        $from = DateTimeImmutable::createFromInterface($since)->setTimezone($utc);
        $to = DateTimeImmutable::createFromInterface($until)->setTimezone($utc);
        $query = ['created' => $from->format('Y-m-d\TH:i:s\Z').'..'.$to->format('Y-m-d\TH:i:s\Z'), 'exclude_pull_requests' => 'true'];
        $path = self::repositoryPath($repository).'/actions/workflows/'.$workflowId.'/runs';
        [$runs, $total] = $this->pages($path, $query, 'workflow_runs', $token, self::RunQueryLimit);

        if ($total <= self::RunQueryLimit) {
            return $runs;
        }

        $seconds = $to->getTimestamp() - $from->getTimestamp();
        if ($seconds < 2) {
            throw new GitHubActionsUnavailable(invalid: true);
        }
        $middle = $from->modify('+'.intdiv($seconds, 2).' seconds');

        return [...$this->workflowRuns($repository, $token, $workflowId, $middle->modify('+1 second'), $to),
            ...$this->workflowRuns($repository, $token, $workflowId, $from, $middle)];
    }

    /**
     * Every job of a run's latest attempt.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws GitHubActionsUnavailable
     */
    public function runJobs(GitHubRepository $repository, #[SensitiveParameter] string $token, int $runId): array
    {
        return $this->pages(self::repositoryPath($repository).'/actions/runs/'.$runId.'/jobs', ['filter' => 'latest'], 'jobs', $token)[0];
    }

    /**
     * The entries of a paged list until GitHub's `total_count` is reached or a short page. It stops after the first page when the
     * `total_count` passes `$most`.
     *
     * @param  array<string, string>  $query
     * @return array{0: list<array<array-key, mixed>>, 1: int} the entries and GitHub's `total_count`
     *
     * @throws GitHubActionsUnavailable
     */
    private function pages(string $path, array $query, string $field, #[SensitiveParameter] string $token, int $most = PHP_INT_MAX): array
    {
        $entries = [];
        $total = 0;

        for ($page = 1; ; $page++) {
            $data = $this->json($path.'?'.http_build_query([...$query, 'per_page' => self::PerPage, 'page' => $page]), $token, limit: self::ListBytes);
            $rows = $data[$field] ?? null;
            $total = $data['total_count'] ?? null;

            if (! is_array($rows) || ! array_is_list($rows) || ! is_int($total)) {
                throw new GitHubActionsUnavailable(invalid: true);
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new GitHubActionsUnavailable(invalid: true);
                }
                $entries[] = $row;
            }

            if (count($rows) < self::PerPage || count($entries) >= $total || $total > $most) {
                break;
            }
        }

        return [$entries, $total];
    }

    /**
     * The storage URL an artifact archive redirects to. Only an HTTPS URL on GitHub's artifact
     * storage hosts, without credentials or a port, is accepted.
     *
     * @throws GitHubActionsUnavailable
     */
    public function archiveLocation(GitHubRepository $repository, int $artifactId, #[SensitiveParameter] string $token): string
    {
        return $this->storageLocation(self::repositoryPath($repository).'/actions/artifacts/'.$artifactId.'/zip', $token);
    }

    /**
     * Streams the job log for redaction before excerpting; the token never reaches log storage.
     *
     * @return Generator<int, string>
     */
    public function jobLog(GitHubRepository $repository, int $jobId): Generator
    {
        $token = $this->token($repository);
        $location = $this->storageLocation(self::repositoryPath($repository).'/actions/jobs/'.$jobId.'/logs', $token);

        yield from $this->stream($location, null, PHP_INT_MAX, null, 10.0);
    }

    private function storageLocation(string $path, #[SensitiveParameter] string $token): string
    {
        $url = self::Api.$path;

        try {
            $response = Http::withToken($token)->withoutRedirecting()->timeout($this->deadline->cap(10))
                ->connectTimeout(5)->withOptions(['stream' => true])->get($url);
            if ($response->status() !== 302) {
                $failure = $this->failure($response);
                $response->toPsrResponse()->getBody()->close();
                throw $failure;
            }
            $location = $response->header('Location');
            $response->toPsrResponse()->getBody()->close();
        } catch (ResourceOperationException|GitHubActionsUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GitHubActionsUnavailable;
        }

        $parts = parse_url($location);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/\A[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:blob\.core\.windows\.net|githubusercontent\.com)\z/D', $host) !== 1) {
            throw new GitHubActionsUnavailable(invalid: true);
        }

        return $location;
    }

    /**
     * Reads a response body up to `$limit` bytes. The token is sent only when given, so an artifact
     * storage URL never receives it.
     *
     * @param  array<string, mixed>|null  $payload
     *
     * @throws GitHubActionsUnavailable
     */
    public function body(
        string $url,
        #[SensitiveParameter] ?string $token,
        int $limit,
        ?array $payload = null,
        float $timeout = 10.0,
    ): string {
        $body = '';
        foreach ($this->stream($url, $token, $limit, $payload, $timeout) as $chunk) {
            $body .= $chunk;
        }

        return $body;
    }

    /**
     * Downloads a response body into a file that the caller created, up to `$limit` bytes.
     *
     * @return string the SHA-256 of the written bytes, in hex
     *
     * @throws GitHubActionsUnavailable
     */
    public function download(string $url, string $file, int $limit, float $timeout = 120.0): string
    {
        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            throw new GitHubActionsUnavailable;
        }

        $hash = hash_init('sha256');

        try {
            foreach ($this->stream($url, null, $limit, null, $timeout) as $chunk) {
                hash_update($hash, $chunk);

                if (@fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new GitHubActionsUnavailable;
                }
            }
        } finally {
            fclose($handle);
        }

        return hash_final($hash);
    }

    private function failure(Response $response): GitHubActionsUnavailable
    {
        $limited = $response->status() === 403
            && ($response->header('x-ratelimit-remaining') === '0'
                || str_contains(strtolower($response->toPsrResponse()->getBody()->read(4096)), 'secondary rate limit'));

        return new GitHubActionsUnavailable($response->status(), rateLimited: $limited);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return Generator<int, string>
     */
    private function stream(
        string $url,
        #[SensitiveParameter] ?string $token,
        int $limit,
        ?array $payload,
        float $timeout,
    ): Generator {
        $stream = null;

        try {
            $request = Http::withoutRedirecting()->timeout($this->deadline->cap($timeout))->connectTimeout(5)
                ->withOptions(['stream' => true])->withHeaders(['Accept' => 'application/vnd.github+json']);

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
            $stream = $response->toPsrResponse()->getBody();

            if (! $response->successful()) {
                throw $this->failure($response);
            }

            $read = 0;

            while (! $stream->eof()) {
                $this->deadline->cap($timeout);
                $data = $stream->read(min(65536, $limit - $read + 1));
                $read += strlen($data);

                if ($read > $limit) {
                    throw new GitHubActionsUnavailable(invalid: true);
                }

                yield $data;
            }
        } catch (ResourceOperationException|GitHubActionsUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GitHubActionsUnavailable;
        } finally {
            $stream?->close();
        }
    }
}
