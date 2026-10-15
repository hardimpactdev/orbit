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
use App\Infrastructure\MachineSoftware\RecipeLoader;
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
        private RecipeLoader $loader,
        private RecipeMaintenanceAlerts $alerts,
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
                $this->require(is_array($commits) && array_is_list($commits) && count($commits) === 1
                    && data_get($commits, '0.sha') === $pull->headSha && data_get($commits, '0.author.login') === $author
                    && data_get($commits, '0.author.type') === 'Bot' && data_get($commits, '0.committer.login') === $author,
                    'Expected exactly one commit authored and committed by the Gateway App.');
                $parents = data_get($commits, '0.parents');
                $parent = data_get($commits, '0.parents.0.sha');
                if (! is_array($parents) || count($parents) !== 1 || ! is_string($parent) || preg_match('/\A[0-9a-f]{40}\z/', $parent) !== 1) {
                    throw new UnexpectedValueException('Expected a single-parent snapshot commit.');
                }
                $this->patch($http, $files, $parent, $pull->headSha, substr($pull->headRef, -8).'T000000Z');
                $this->metadata($http->get($path)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author);
                $merged = $this->github->mergePullRequest($token, $repository, $pull->number, $pull->headSha);
                $this->require($merged->merged, 'GitHub refused the expected-head merge; recheck the PR and branch protection.');
            } catch (Throwable $error) {
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
        $this->require(data_get($pr, 'changed_files') === 1 && data_get($pr, 'additions') === 1 && data_get($pr, 'deletions') === 1,
            'Only one snapshot line may change.');
    }

    private function patch(PendingRequest $http, mixed $files, string $parent, string $head, string $snapshot): void
    {
        $this->require(is_array($files) && array_is_list($files) && count($files) === 1
            && data_get($files, '0.filename') === OpenNightlyRecipePullRequestAction::Path && data_get($files, '0.status') === 'modified'
            && data_get($files, '0.additions') === 1 && data_get($files, '0.deletions') === 1 && data_get($files, '0.changes') === 2,
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
        if (count($removed) !== 1 || count($added) !== 1) {
            throw new UnexpectedValueException('The recipe patch changes more than one line.');
        }
        $before = $this->contents($http, $parent);
        $after = $this->contents($http, $head);
        $old = $this->loader->decode($before)->snapshot;
        $this->require($snapshot > $old && $this->loader->decode($after)->snapshot === $snapshot
            && preg_match('/\A\s*"snapshot"\s*:\s*"'.preg_quote($old, '/').'"\s*,?\z/', $removed[0]) === 1
            && $added[0] === str_replace($old, $snapshot, $removed[0]), 'The patch does not advance only ubuntu.snapshot to its branch date.');
        $expected = str_replace($removed[0], $added[0], $before, $count);
        $this->require($count === 1 && $after === $expected, 'Recipe contents include changes outside the snapshot line.');
    }

    private function contents(PendingRequest $http, string $sha): string
    {
        $encoded = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $sha])->throw()->json('content');
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($content)) {
            throw new UnexpectedValueException('GitHub omitted the recipe contents.');
        }

        return $content;
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
            $this->alerts->halted($summary);
        }
    }
}
