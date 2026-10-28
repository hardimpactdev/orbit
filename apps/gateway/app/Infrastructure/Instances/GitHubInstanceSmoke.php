<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Data\Instances\InstanceSmokeRequestData;
use App\Data\Instances\InstanceSmokeResultData;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Instances\Smoke\SmokeObservation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class GitHubInstanceSmoke
{
    public function __construct(
        private GitHubActionsReader $actions,
        private GitHubApi $api,
        private GitHubAppStore $apps,
        private SmokeArtifactEvidence $evidence,
        private GitHubTokenCache $tokens,
    ) {}

    /** @return array{id: int, sha: string} */
    public function resolve(GitHubRepository $repository, InstanceSmokeRequestData $request): array
    {
        $credentials = $this->apps->credentials() ?? throw new GitHubActionsUnavailable(404);
        try {
            $installation = $this->api->repositoryInstallation($credentials, $repository)
                ?? throw new GitHubActionsUnavailable(404);
            $token = $this->api->repositoryReadToken($credentials, $installation, $repository);
        } catch (GitHubApiException $exception) {
            throw new GitHubActionsUnavailable($exception->isTokenRefusal() ? $exception->getCode() : null);
        }
        $path = GitHubActionsReader::repositoryPath($repository);
        $commit = $this->actions->json($path.'/commits/'.rawurlencode($request->ref), $token);
        $sha = $commit['sha'] ?? null;
        $workflow = $this->actions->json(
            $path.'/actions/workflows/'.rawurlencode(basename($request->workflow)),
            $this->readToken($repository),
        );
        if (($workflow['path'] ?? null) !== $request->workflow) {
            throw new ResourceOperationException('instance.smoke_invalid', 'The workflow path does not match.');
        }
        if (($workflow['state'] ?? null) !== 'active') {
            throw new ResourceOperationException('instance.smoke_invalid', 'The workflow is not active.');
        }
        if (! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/D', $sha) !== 1) {
            throw new ResourceOperationException('instance.smoke_invalid', 'The workflow ref has no full commit SHA.');
        }
        if (! is_int($workflow['id'] ?? null) || $workflow['id'] < 1) {
            throw new GitHubActionsUnavailable(invalid: true);
        }

        return ['id' => $workflow['id'], 'sha' => $sha];
    }

    public function send(
        GitHubRepository $repository,
        int $workflowId,
        InstanceSmokeRequestData $request,
        #[\SensitiveParameter] string $token,
    ): void {
        $this->actions->body(
            GitHubActionsReader::Api.GitHubActionsReader::repositoryPath($repository)
                .'/actions/workflows/'.$workflowId.'/dispatches',
            $token,
            1024,
            ['ref' => $request->ref, 'inputs' => [
                'commit' => $request->commit,
                'deployment_id' => (string) $request->deploymentId,
                'base_url' => $request->baseUrl,
            ]],
        );
    }

    /** @param array{id: int, sha: string} $workflow */
    public function observe(
        GitHubRepository $repository,
        InstanceSmokeRequestData $request,
        array $workflow,
        string $since,
    ): SmokeObservation {
        $result = InstanceSmokeResultData::from([...$request->toArray(), 'workflow_sha' => $workflow['sha']]);
        $path = GitHubActionsReader::repositoryPath($repository);
        $token = $this->readToken($repository);
        $earliest = Carbon::parse($since)->subSeconds(60);
        $runs = $this->actions->json($path.'/actions/workflows/'.$workflow['id'].'/runs?'.http_build_query([
            'event' => 'workflow_dispatch', 'head_sha' => $workflow['sha'],
            'created' => '>='.$earliest->toIso8601String(), 'per_page' => 100,
        ]), $token);
        $matches = array_values(array_filter($this->rows($runs, 'workflow_runs'),
            static fn (array $row): bool => ($row['event'] ?? null) === 'workflow_dispatch'
                && ($row['workflow_id'] ?? null) === $workflow['id']
                && ($row['head_sha'] ?? null) === $workflow['sha']
                && ($row['display_title'] ?? null) === 'Smoke '.$request->deploymentId.' '.$request->commit
                && is_string($row['created_at'] ?? null)
                && Carbon::parse($row['created_at'])->greaterThanOrEqualTo($earliest)));
        if ($matches === []) {
            return new SmokeObservation($result);
        }
        if (count($matches) !== 1) {
            return $this->unknown($result, 'instance.smoke_run_ambiguous');
        }
        $id = $matches[0]['id'] ?? null;
        $attempt = $matches[0]['run_attempt'] ?? null;
        if (! is_int($id) || $id < 1 || ! is_int($attempt) || $attempt < 1) {
            return $this->unknown($result);
        }
        $run = $this->actions->json($path.'/actions/runs/'.$id.'/attempts/'.$attempt, $token);
        if (($run['id'] ?? null) !== $id || ($run['run_attempt'] ?? null) !== $attempt
            || ($run['workflow_id'] ?? null) !== $workflow['id']
            || ($run['event'] ?? null) !== 'workflow_dispatch'
            || ($run['head_sha'] ?? null) !== $workflow['sha']
            || ($run['path'] ?? null) !== $request->workflow
            || ($run['head_branch'] ?? null) !== preg_replace('~^refs/(heads|tags)/~', '', $request->ref)
            || ! $this->sameRepository($run['repository'] ?? null, $repository)
            || ! $this->sameRepository($run['head_repository'] ?? null, $repository)) {
            return $this->unknown($result);
        }
        $result->runId = $id;
        $result->runAttempt = $attempt;
        $result->runUrl = 'https://github.com/'.$repository->owner.'/'.$repository->name.'/actions/runs/'.$id;
        $result->conclusion = is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null;
        if (($run['status'] ?? null) !== 'completed') {
            return new SmokeObservation($result);
        }
        $listing = $this->actions->json($path.'/actions/runs/'.$id.'/artifacts?per_page=100', $token);
        $artifacts = array_values(array_filter($this->rows($listing, 'artifacts'),
            static fn (array $a): bool => ($a['name'] ?? null) === 'production-smoke-'.$id.'-'.$attempt
                && ($a['expired'] ?? null) === false));
        if (count($artifacts) !== 1) {
            return $this->unknown($result, 'instance.smoke_artifact_missing');
        }
        $artifact = $artifacts[0];
        if (! is_int($artifact['id'] ?? null) || ! is_int($artifact['size_in_bytes'] ?? null)
            || $artifact['size_in_bytes'] < 1 || $artifact['size_in_bytes'] > 8 * 1024 * 1024
            || ! is_string($artifact['digest'] ?? null)) {
            return $this->unknown($result);
        }
        $file = tmpfile();
        if ($file === false) {
            throw new GitHubActionsUnavailable;
        }
        try {
            $name = stream_get_meta_data($file)['uri'] ?? null;
            if (! is_string($name)) {
                throw new GitHubActionsUnavailable;
            }
            $location = $this->actions->archiveLocation($repository, $artifact['id'], $token);
            $digest = $this->actions->download($location, $name, 8 * 1024 * 1024, 10.0);
            if (! hash_equals('sha256:'.$digest, $artifact['digest'])) {
                return $this->unknown($result);
            }
            $result->artifacts = [[
                'name' => $artifact['name'],
                'url' => $result->runUrl.'/artifacts/'.$artifact['id'],
            ]];
            $result = $this->evidence->verify($name, $result);

            return $result->state === 'unknown'
                ? $this->unknown($result, $result->tests > 0 && $result->skipped === 0
                    ? 'instance.smoke_run_unsuccessful' : 'instance.smoke_evidence_invalid')
                : new SmokeObservation($result);
        } catch (Throwable $exception) {
            return $this->unavailable($result, $exception, $repository);
        } finally {
            fclose($file);
        }
    }

    public function unavailable(
        InstanceSmokeResultData $result,
        Throwable $exception,
        GitHubRepository $repository,
    ): SmokeObservation {
        if ($exception instanceof GitHubActionsUnavailable) {
            if ($exception->retryable()) {
                return new SmokeObservation($result, 'instance.smoke_github_unavailable');
            }
            if (in_array($exception->status, [401, 403], true)) {
                $this->tokens->forget($repository);

                return $this->unknown($result, 'instance.smoke_github_auth');
            }
        }

        return $this->unknown($result);
    }

    private function readToken(GitHubRepository $repository): string
    {
        $credentials = $this->apps->credentials() ?? throw new GitHubActionsUnavailable(404);
        try {
            $installation = $this->tokens->installation($credentials->appId, $repository,
                fn (): ?int => $this->api->repositoryInstallation($credentials, $repository))
                ?? throw new GitHubActionsUnavailable(404);

            return $this->tokens->token($credentials->appId, $installation, $repository, 'actions',
                fn (): string => $this->actions->token($repository));
        } catch (GitHubApiException $exception) {
            throw new GitHubActionsUnavailable($exception->isTokenRefusal() ? $exception->getCode() : null);
        }
    }

    private function unknown(
        InstanceSmokeResultData $result,
        string $code = 'instance.smoke_evidence_invalid',
    ): SmokeObservation {
        $result->state = 'unknown';

        return new SmokeObservation($result, $code);
    }

    private function sameRepository(mixed $value, GitHubRepository $repository): bool
    {
        return is_array($value) && is_string($value['full_name'] ?? null)
            && strcasecmp($value['full_name'], $repository->owner.'/'.$repository->name) === 0;
    }

    /**
     * @param  array<array-key, mixed>  $listing
     * @return list<array<string, mixed>>
     */
    private function rows(array $listing, string $key): array
    {
        $rows = $listing[$key] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100
            || ($listing['total_count'] ?? null) !== count($rows)) {
            throw new GitHubActionsUnavailable(invalid: true);
        }

        return array_map(ValidatedData::object(...), $rows);
    }
}
