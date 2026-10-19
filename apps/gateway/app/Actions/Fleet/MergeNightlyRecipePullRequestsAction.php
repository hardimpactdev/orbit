<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/** A narrow exception to manual PR review: only the App's one-commit snapshot bump may merge on green. */
final readonly class MergeNightlyRecipePullRequestsAction
{
    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private GitHubAppStore $store,
        private RecipeMaintenanceAlerts $alerts,
        private NightlyRecipeContents $contents,
    ) {}

    public function execute(): void
    {
        $credentials = $this->store->credentials();
        $repository = GitHubRepository::fromOrigin('https://github.com/'.FleetRolloutAlerts::repository());
        if ($credentials === null || $repository === null) {
            return;
        }
        $scope = 'machine-recipe:nightly-merge:'.$repository->owner.'/'.$repository->name;
        if (Cache::has($scope.':list-retry')) {
            return;
        }
        try {
            $token = $this->access->token($repository);
            $pulls = $this->github->openPullRequests($token, $repository);
        } catch (Throwable) {
            Cache::put($scope.':list-retry', true, 300);
            $this->blocked('list', 'Nightly recipe PRs could not be read. No automatic merge was attempted.');

            return;
        }
        foreach ($pulls as $pull) {
            if (preg_match('/\Anightly\/recipe-[0-9]{8}\z/', $pull->headRef) !== 1) {
                continue;
            }
            $blockedHeadKey = 'machine-recipe:blocked-pr-head:'.$repository->owner.'/'.$repository->name.':'.$pull->number;
            $retryKey = $scope.':retry:'.$pull->number.':'.$pull->headSha;
            if (Cache::has($retryKey)) {
                continue;
            }
            try {
                $http = Http::baseUrl('https://api.github.com/repos/'.$repository->owner.'/'.$repository->name)
                    ->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
                $path = '/pulls/'.$pull->number;
                $author = $credentials->slug.'[bot]';
                $blocked = Cache::get($blockedHeadKey);
                if (is_array($blocked) && ($blocked['sha'] ?? null) === $pull->headSha && is_string($blocked['reason'] ?? null)) {
                    $this->supersede($http, $repository, $pull, $pulls, $author, $blocked['reason']);

                    continue;
                }
                $checksToken = $this->access->checksToken($repository);
                $check = config()->string('orbit.gateway_releases.check');
                if ($checksToken === null) {
                    throw new RuntimeException('GitHub checks access is unavailable.');
                }
                if (RequiredCheckState::of($this->github->checkRuns($checksToken, $repository, $pull->headSha, $check), $pull->headSha, $check) !== RequiredCheckState::Passed) {
                    continue;
                }
                try {
                    $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                    $commits = $http->get($path.'/commits', ['per_page' => 2])->throw()->json();
                    $files = $http->get($path.'/files', ['per_page' => 2])->throw()->json();
                    $this->require($this->contents->appCommit($commits, $pull->headSha, $author),
                        'Expected exactly one Gateway App commit with a verified GitHub web-flow signature.');
                    $parents = data_get($commits, '0.parents');
                    $parent = data_get($commits, '0.parents.0.sha');
                    if (! is_array($parents) || count($parents) !== 1 || ! $this->contents->isSha($parent)) {
                        throw new UnexpectedValueException('Expected a single-parent snapshot commit.');
                    }
                    $this->patch($http, $files, $parent, $pull->headSha, substr($pull->headRef, -8).'T000000Z');
                    $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                } catch (UnexpectedValueException $error) {
                    Cache::forever($blockedHeadKey, ['sha' => $pull->headSha, 'reason' => $error->getMessage()]);
                    $this->blocked($pull->number.':'.$pull->headSha, "Nightly recipe PR [{$pull->url}] blocked: {$error->getMessage()}");

                    continue;
                }
                $merged = $this->github->mergePullRequest($token, $repository, $pull->number, $pull->headSha);
                if (! $merged->merged) {
                    throw new RuntimeException('GitHub refused the expected-head merge; recheck the PR and branch protection.');
                }
            } catch (Throwable $error) {
                Cache::put($retryKey, true, 300);
                $reason = $error instanceof RequestException || $error instanceof ConnectionException ? 'GitHub request failed.' : $error->getMessage();
                $this->blocked($pull->number.':'.$pull->headSha, "Nightly recipe PR [{$pull->url}] could not be completed; retrying in five minutes. {$reason}");
            }
        }
    }

    /** @param list<GitHubListedPullRequest> $pulls */
    private function supersede(PendingRequest $http, GitHubRepository $repository, GitHubListedPullRequest $pull, array $pulls, string $author, string $reason): void
    {
        if ($pull->authorLogin !== $author) {
            return;
        }
        foreach ($pulls as $replacement) {
            if (preg_match('/\Anightly\/recipe-[0-9]{8}\z/', $replacement->headRef) !== 1
                || $replacement->headRef <= $pull->headRef || $replacement->authorLogin !== $author) {
                continue;
            }
            $this->metadata($http->get('/pulls/'.$replacement->number)->throw()->json(), $replacement->headSha, $replacement->headRef, $repository, $author);
            $pr = $http->get('/pulls/'.$pull->number)->throw()->json();
            if (! $this->identity($pr, $pull->headSha, $pull->headRef, $repository, $author)) {
                return;
            }
            $http->post('/issues/'.$pull->number.'/comments', ['body' => "Closing this blocked nightly recipe PR in favor of {$replacement->url}. Recipe evidence was rejected: {$reason}"])->throw();
            $http->patch('/pulls/'.$pull->number, ['state' => 'closed'])->throw();

            return;
        }
    }

    private function metadata(mixed $pr, string $sha, string $branch, GitHubRepository $repository, string $author): void
    {
        $this->require($this->identity($pr, $sha, $branch, $repository, $author), 'The open PR target, head, or Gateway App author changed.');
        $this->require(data_get($pr, 'commits') === 1, 'Extra commits block automatic merge.');
        $this->require(data_get($pr, 'changed_files') === 1,
            'Only the recipe snapshot and Ubuntu pins may change.');
    }

    private function identity(mixed $pr, string $sha, string $branch, GitHubRepository $repository, string $author): bool
    {
        $name = $repository->owner.'/'.$repository->name;

        return data_get($pr, 'state') === 'open' && data_get($pr, 'draft') === false
            && data_get($pr, 'base.ref') === 'main' && data_get($pr, 'base.repo.full_name') === $name
            && data_get($pr, 'head.ref') === $branch && data_get($pr, 'head.repo.full_name') === $name && data_get($pr, 'head.sha') === $sha
            && data_get($pr, 'user.login') === $author && data_get($pr, 'user.type') === 'Bot';
    }

    private function patch(PendingRequest $http, mixed $files, string $parent, string $head, string $snapshot): void
    {
        $this->require(is_array($files) && array_is_list($files) && count($files) === 1
            && data_get($files, '0.filename') === OpenNightlyRecipePullRequestAction::Path && data_get($files, '0.status') === 'modified',
            'The PR files are not exactly the recipe snapshot change.');
        $patch = data_get($files, '0.patch');
        if (! is_string($patch)) {
            throw new UnexpectedValueException('GitHub omitted the recipe patch.');
        }
        $removed = $added = [];
        foreach (explode("\n", $patch) as $line) {
            if (str_starts_with($line, '-')) {
                $removed[] = substr($line, 1);
            } elseif (str_starts_with($line, '+')) {
                $added[] = substr($line, 1);
            }
        }
        $before = $this->contents->read($http, $parent);
        $after = $this->contents->read($http, $head);
        $old = $this->contents->snapshot($before);
        $this->require($snapshot > $old && $this->contents->snapshot($after) === $snapshot,
            'The patch must advance ubuntu.snapshot to its branch date.');
        $expected = $this->contents->replace($before, $old, $snapshot);
        $this->require($after === $expected, 'Recipe contents differ from the authenticated snapshot and Ubuntu pin bump.');
        $oldLines = explode("\n", $before);
        $newLines = explode("\n", $expected);
        $expectedRemoved = $expectedAdded = [];
        foreach ($oldLines as $index => $line) {
            if ($line !== ($newLines[$index] ?? null)) {
                $expectedRemoved[] = $line;
                $expectedAdded[] = $newLines[$index] ?? '';
            }
        }
        $this->require($removed === $expectedRemoved && $added === $expectedAdded
            && data_get($files, '0.additions') === count($added) && data_get($files, '0.deletions') === count($removed)
            && data_get($files, '0.changes') === count($added) + count($removed), 'The recipe patch does not match the authenticated bump.');
    }

    private function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new UnexpectedValueException($reason);
        }
    }

    private function blocked(string $key, string $summary): void
    {
        if (Cache::add('machine-recipe:blocked-pr:'.$key, true, 86400)) {
            $this->alerts->halted($summary, incident: RecipeMaintenanceAlerts::mergeIncident($key));
        }
    }
}
