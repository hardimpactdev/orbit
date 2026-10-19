<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        try {
            $token = $this->access->token($repository);
            $pulls = $this->github->openPullRequests($token, $repository);
        } catch (Throwable) {
            $this->blocked('list', 'Nightly recipe PRs could not be read. No automatic merge was attempted.');

            return;
        }
        foreach ($pulls as $pull) {
            if (preg_match('/\Anightly\/recipe-[0-9]{8}\z/', $pull->headRef) !== 1) {
                continue;
            }
            $blockedHeadKey = 'machine-recipe:blocked-pr-head:'.$repository->owner.'/'.$repository->name.':'.$pull->number;
            if (Cache::get($blockedHeadKey) === $pull->headSha) {
                continue;
            }
            try {
                $http = Http::baseUrl('https://api.github.com/repos/'.$repository->owner.'/'.$repository->name)
                    ->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
                $path = '/pulls/'.$pull->number;
                $author = $credentials->slug.'[bot]';
                $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                $checksToken = $this->access->checksToken($repository);
                $check = config()->string('orbit.gateway_releases.check');
                if ($checksToken === null || RequiredCheckState::of($this->github->checkRuns($checksToken, $repository, $pull->headSha, $check), $pull->headSha, $check) !== RequiredCheckState::Passed) {
                    continue;
                }
                // Read the evidence after green, immediately before the head-fenced merge.
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
                $merged = $this->github->mergePullRequest($token, $repository, $pull->number, $pull->headSha);
                $this->require($merged->merged, 'GitHub refused the expected-head merge; recheck the PR and branch protection.');
            } catch (Throwable $error) {
                Cache::forever($blockedHeadKey, $pull->headSha);
                $reason = $error instanceof UnexpectedValueException ? $error->getMessage() : 'GitHub evidence could not be verified.';
                $this->blocked($pull->number.':'.$pull->headSha, "Nightly recipe PR [{$pull->url}] blocked: {$reason}");
            }
        }
    }

    private function metadata(mixed $pr, string $sha, string $branch, GitHubRepository $repository, string $author): void
    {
        $name = $repository->owner.'/'.$repository->name;
        $this->require(data_get($pr, 'state') === 'open' && data_get($pr, 'draft') === false
            && data_get($pr, 'base.ref') === 'main' && data_get($pr, 'base.repo.full_name') === $name
            && data_get($pr, 'head.ref') === $branch && data_get($pr, 'head.repo.full_name') === $name && data_get($pr, 'head.sha') === $sha,
            'The open PR target or head changed.');
        $this->require(data_get($pr, 'user.login') === $author && data_get($pr, 'user.type') === 'Bot', 'The PR author is not the Gateway App.');
        $this->require(data_get($pr, 'commits') === 1, 'Extra commits block automatic merge.');
        $this->require(data_get($pr, 'changed_files') === 1,
            'Only the recipe snapshot and Ubuntu pins may change.');
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
