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
use App\Infrastructure\MachineSoftware\RecipeArtifactsBump;
use App\Infrastructure\MachineSoftware\RecipeLoader;
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
        private RetireNightlyRecipePullRequestsAction $retire,
        private RecipeArtifactsBump $artifacts,
        private RecipeLoader $loader,
    ) {}

    public function execute(): void
    {
        $credentials = $this->store->credentials();
        $repository = GitHubRepository::fromOrigin('https://github.com/'.FleetRolloutAlerts::repository());
        if ($credentials === null || $repository === null) {
            return;
        }
        $retrySeconds = RetireNightlyRecipePullRequestsAction::RetrySeconds;
        $scope = 'machine-recipe:nightly-merge:'.$repository->owner.'/'.$repository->name;
        if (Cache::has($scope.':retry')) {
            return;
        }
        try {
            $token = $this->access->token($repository);
            $pulls = $this->github->openPullRequests($token, $repository);
        } catch (Throwable) {
            Cache::put($scope.':retry', true, $retrySeconds);
            $this->alerts->haltedOnce("Nightly recipe PRs could not be read; retrying in {$retrySeconds} seconds. No automatic merge was attempted.", RecipeMaintenanceAlerts::repositoryIncident('github_list_failed'));

            return;
        }
        $http = Http::baseUrl('https://api.github.com/repos/'.$repository->owner.'/'.$repository->name)
            ->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
        $author = $credentials->slug.'[bot]';
        $pulls = array_values(array_filter($pulls, fn (GitHubListedPullRequest $pull): bool => $this->contents->nightlySnapshot($pull->headRef) !== null || preg_match('/\Anightly\/artifacts-[0-9a-f]{12}\z/', $pull->headRef) === 1));
        if ($pulls === []) {
            return;
        }
        try {
            $pulls = $this->retire->execute($http, $repository, $pulls, $author, $this->contents->read($http, 'main'));
        } catch (Throwable) {
            Cache::put($scope.':retry', true, $retrySeconds);
            $this->alerts->haltedOnce("Nightly recipe main snapshot could not be read; retrying in {$retrySeconds} seconds.", RecipeMaintenanceAlerts::repositoryIncident('main_snapshot_failed'));

            return;
        }
        foreach ($pulls as $pull) {
            $artifactUpdate = preg_match('/\Anightly\/artifacts-[0-9a-f]{12}\z/', $pull->headRef) === 1;
            $blockedHeadKey = $this->blockedHeadKey($repository, $pull->number);
            $blocked = Cache::get($blockedHeadKey);
            $retryKey = $scope.':retry:'.$pull->number.':'.$pull->headSha;
            if ((is_array($blocked) && ($blocked['sha'] ?? null) === $pull->headSha) || Cache::has($retryKey)) {
                continue;
            }
            try {
                $path = '/pulls/'.$pull->number;
                $checksToken = $this->access->checksToken($repository);
                $check = config()->string('orbit.gateway_releases.check');
                if ($checksToken === null) {
                    throw new RuntimeException('GitHub checks access is unavailable.');
                }
                $green = RequiredCheckState::of($this->github->checkRuns($checksToken, $repository, $pull->headSha, $check), $pull->headSha, $check) === RequiredCheckState::Passed;
                if (! $artifactUpdate && ! $green) {
                    continue;
                }
                try {
                    $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                    $verifiedHeadKey = $scope.':verified:'.$pull->number;
                    if ($artifactUpdate || Cache::get($verifiedHeadKey) !== $pull->headSha) {
                        $commits = $http->get($path.'/commits', ['per_page' => 2])->throw()->json();
                        $files = $http->get($path.'/files', ['per_page' => 2])->throw()->json();
                        $this->require($this->contents->appCommit($commits, $pull->headSha, $author),
                            'Expected exactly one Gateway App commit with a verified GitHub web-flow signature.');
                        $parents = data_get($commits, '0.parents');
                        $parent = data_get($commits, '0.parents.0.sha');
                        if (! is_array($parents) || count($parents) !== 1 || ! $this->contents->isSha($parent)) {
                            throw new UnexpectedValueException('Expected a single-parent snapshot commit.');
                        }
                        if ($artifactUpdate) {
                            $current = $this->artifactPatch($http, $files, $parent, $pull->headSha, $pull->headRef);
                            if ($current === null) {
                                continue;
                            }
                            if (! $current) {
                                $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                                $http->patch($path, ['state' => 'closed'])->throw();

                                continue;
                            }
                        } else {
                            $this->patch($http, $files, $parent, $pull->headSha, substr($pull->headRef, -8).'T000000Z');
                        }
                        $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                        if (! $artifactUpdate) {
                            Cache::forever($verifiedHeadKey, $pull->headSha);
                        }
                    }
                } catch (UnexpectedValueException $error) {
                    Cache::forever($blockedHeadKey, ['sha' => $pull->headSha, 'reason' => $error->getMessage()]);
                    $this->alerts->haltedOnce("Nightly recipe PR [{$pull->url}] blocked: {$error->getMessage()}", RecipeMaintenanceAlerts::pullIncident($pull->number, $pull->headSha, 'merge_blocked'));

                    return;
                }
                if (! $green) {
                    continue;
                }
                $merged = $this->github->mergePullRequest($token, $repository, $pull->number, $pull->headSha);
                if (! $merged->merged) {
                    Cache::put($retryKey, true, $retrySeconds);
                    $this->alerts->haltedOnce("Nightly recipe PR [{$pull->url}] merge refused (GitHub HTTP {$merged->status}): {$merged->message}. Recheck the PR and branch protection; retrying in {$retrySeconds} seconds.", RecipeMaintenanceAlerts::pullIncident($pull->number, $pull->headSha, 'merge_refused'));
                }
            } catch (Throwable $error) {
                Cache::put($retryKey, true, $retrySeconds);
                $reason = $error instanceof RequestException || $error instanceof ConnectionException ? 'GitHub request failed.' : $error->getMessage();
                $this->alerts->haltedOnce("Nightly recipe PR [{$pull->url}] could not be completed; retrying in {$retrySeconds} seconds. {$reason}", RecipeMaintenanceAlerts::pullIncident($pull->number, $pull->headSha, 'merge_retry'));
            }
        }
    }

    private function blockedHeadKey(GitHubRepository $repository, int $number): string
    {
        return 'machine-recipe:blocked-pr-head:'.$repository->owner.'/'.$repository->name.':'.$number;
    }

    private function metadata(mixed $pr, string $sha, string $branch, GitHubRepository $repository, string $author): void
    {
        $this->require($this->contents->appPull($pr, $sha, $branch, $repository, $author) && data_get($pr, 'draft') === false, 'The open PR target, head, or Gateway App author changed.');
        $this->require(data_get($pr, 'commits') === 1, 'Extra commits block automatic merge.');
        $this->require(data_get($pr, 'changed_files') === 1,
            'Only the recipe snapshot, Ubuntu pins, or authenticated artifact pins may change.');
    }

    private function artifactPatch(PendingRequest $http, mixed $files, string $parent, string $head, string $branch): ?bool
    {
        $this->require(is_array($files) && array_is_list($files) && count($files) === 1
            && data_get($files, '0.filename') === OpenNightlyRecipePullRequestAction::Path && data_get($files, '0.status') === 'modified',
            'Artifact updates may change only the recipe.');
        $before = $this->contents->read($http, $parent);
        $after = $this->contents->read($http, $head);
        $this->loader->decode($before);
        $this->loader->decode($after);
        $this->artifacts->validateChange($before, $after);
        $this->require($branch === OpenRecipeArtifactsPullRequestAction::BranchPrefix.$this->artifacts->fingerprint($after), 'The artifact branch does not name its pins.');
        $key = 'machine-recipe:artifact-proof:'.hash('sha256', $parent.$head.$before.$after);
        $latest = Cache::get($key);
        if (! is_string($latest)) {
            // Failures cool down too; network outages and incomplete builds must not rerun each minute.
            if (! Cache::add($key.':attempt', true, 3600)) {
                return null;
            }
            $latest = $this->artifacts->indexes($before);
            $this->loader->decode($latest);
            if ($latest !== $before) {
                $this->artifacts->validateChange($before, $latest);
            }
            Cache::put($key, $latest, 3600);
        }

        return $latest === $after;
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
}
