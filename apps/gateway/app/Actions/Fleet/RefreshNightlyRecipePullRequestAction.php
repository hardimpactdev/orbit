<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Rebuild only an untouched, failed App snapshot commit whose parent main has superseded. */
final readonly class RefreshNightlyRecipePullRequestAction
{
    public function __construct(private GitHubAppStore $store, private GitHubApi $github, private RepositoryPullRequestAccess $access, private RecipeLoader $loader, private NightlyRecipeContents $contents, private RecipeMaintenanceAlerts $alerts) {}

    public function execute(): void
    {
        try {
            $repository = GitHubRepository::fromOrigin('https://github.com/'.FleetRolloutAlerts::repository());
            $credentials = $this->store->credentials();
            if ($repository === null || $credentials === null) {
                return;
            }
            $token = $this->access->token($repository);
            $pulls = $this->github->openPullRequests($token, $repository);
            if ($pulls === []) {
                return;
            }
            $http = Http::baseUrl('https://api.github.com/repos/'.$repository->owner.'/'.$repository->name)
                ->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
            $main = $http->get('/git/ref/heads/main')->throw()->json('object.sha');
            if (! $this->contents->isSha($main)) {
                throw new \RuntimeException('GitHub did not return the main commit.');
            }
            $content = $this->contents->read($http, $main);
            foreach ($pulls as $pull) {
                if (preg_match('/\Anightly\/recipe-[0-9]{8}\z/', $pull->headRef) !== 1 || $pull->authorLogin !== $credentials->slug.'[bot]') {
                    continue;
                }
                try {
                    $this->refresh($http, $repository, $pull->number, $pull->headRef, $main, $content);
                } catch (Throwable) {
                    $this->alerts->halted('Nightly recipe PR '.$pull->url.' could not be recovered. Inspect GitHub before retrying.');
                }
            }
        } catch (Throwable) {
            $this->alerts->halted('Nightly recipe recovery could not read GitHub. No further recovery was attempted.');
        }
    }

    private function refresh(PendingRequest $http, GitHubRepository $repository, int $number, string $branch, string $main, string $content): void
    {
        $author = $this->store->credentials()?->slug;
        if ($author === null) {
            return;
        }
        $author .= '[bot]';
        $path = '/pulls/'.$number;
        $pr = $http->get($path)->throw()->json();
        $sha = data_get($pr, 'head.sha');
        $name = $repository->owner.'/'.$repository->name;
        if (! $this->contents->isSha($sha)
            || data_get($pr, 'state') !== 'open' || data_get($pr, 'draft') !== false
            || data_get($pr, 'user.login') !== $author || data_get($pr, 'user.type') !== 'Bot'
            || data_get($pr, 'base.ref') !== 'main' || data_get($pr, 'base.repo.full_name') !== $name
            || data_get($pr, 'head.ref') !== $branch || data_get($pr, 'head.repo.full_name') !== $name
            || data_get($pr, 'commits') !== 1 || data_get($pr, 'changed_files') !== 1
            || data_get($pr, 'additions') !== 1 || data_get($pr, 'deletions') !== 1) {
            return;
        }
        $checksToken = $this->access->checksToken($repository);
        $check = config()->string('orbit.gateway_releases.check');
        $runs = $checksToken === null ? [] : $this->github->checkRuns($checksToken, $repository, $sha, $check);
        // Missing, pending, or spoofed checks are not evidence of a failed CI run.
        if ($runs === [] || ! array_all($runs, static fn (GitHubCheckRun $run): bool => $run->appSlug === RequiredCheckState::CheckApp && $run->headSha === $sha)
            || RequiredCheckState::of($runs, $sha, $check) !== RequiredCheckState::Failed) {
            return;
        }
        $commits = $http->get($path.'/commits', ['per_page' => 2])->throw()->json();
        $parent = data_get($commits, '0.parents.0.sha');
        $parents = data_get($commits, '0.parents');
        if (! is_array($commits) || count($commits) !== 1 || data_get($commits, '0.sha') !== $sha
            || data_get($commits, '0.author.login') !== $author || data_get($commits, '0.author.type') !== 'Bot'
            || data_get($commits, '0.committer.login') !== $author || data_get($commits, '0.committer.type') !== 'Bot'
            || ! is_array($parents) || count($parents) !== 1
            || ! $this->contents->isSha($parent) || $parent === $main) {
            return;
        }
        if ($http->get('/compare/'.$parent.'...'.$main)->throw()->json('status') !== 'ahead') {
            return;
        }
        $files = $http->get($path.'/files', ['per_page' => 2])->throw()->json();
        if (! is_array($files) || count($files) !== 1
            || data_get($files, '0.filename') !== OpenNightlyRecipePullRequestAction::Path || data_get($files, '0.status') !== 'modified'
            || data_get($files, '0.additions') !== 1 || data_get($files, '0.deletions') !== 1 || data_get($files, '0.changes') !== 2) {
            return;
        }
        $before = $this->contents->read($http, $parent);
        $after = $this->contents->read($http, $sha);
        $snapshot = substr($branch, -8).'T000000Z';
        $old = $this->loader->decode($before)->snapshot;
        $expected = $this->contents->replace($before, $old, $snapshot);
        if ($old >= $snapshot || $expected !== $after) {
            return;
        }
        // Fail closed on truncated history or non-App modifications, including a close/reopen or force push.
        if (! $this->untouched($http, $number, $author, $sha)) {
            return;
        }
        // REST timelines omit body edits. Read the complete edit history as well, failing closed.
        $history = $http->post('https://api.github.com/graphql', [
            'query' => 'query($owner: String!, $name: String!, $number: Int!) { repository(owner: $owner, name: $name) { id pullRequest(number: $number) { userContentEdits(first: 100) { nodes { editor { login } } pageInfo { hasNextPage } } } } }',
            'variables' => ['owner' => $repository->owner, 'name' => $repository->name, 'number' => $number],
        ])->throw()->json();
        $repositoryId = data_get($history, 'data.repository.id');
        $edits = data_get($history, 'data.repository.pullRequest.userContentEdits.nodes');
        if (! is_string($repositoryId) || data_get($history, 'errors') !== null || ! is_array($edits)
            || data_get($history, 'data.repository.pullRequest.userContentEdits.pageInfo.hasNextPage') !== false
            || ! array_all($edits, static fn (mixed $edit): bool => data_get($edit, 'editor.login') === $author)) {
            return;
        }
        $today = now('Europe/Amsterdam')->format('Ymd').'T000000Z';
        if ($snapshot < $today) {
            if ($http->get($path)->throw()->json() !== $pr || ! $this->untouched($http, $number, $author, $sha)) {
                return;
            }
            $http->patch($path, ['state' => 'closed'])->throw();
            $this->comment($http, $number, 'Superseded this failed nightly snapshot by tonight\'s date PR on current main. The original branch is unchanged.');

            return;
        }
        if ($snapshot !== $today || $this->loader->decode($content)->snapshot >= $snapshot) {
            return;
        }
        $updated = $this->contents->replace($content, $this->loader->decode($content)->snapshot, $snapshot);
        $this->loader->decode($updated);
        $title = 'Bump machine recipe snapshot to '.$snapshot;
        $baseTree = $http->get('/git/commits/'.$main)->throw()->json('tree.sha');
        if (! $this->contents->isSha($baseTree)) {
            return;
        }
        $tree = $http->post('/git/trees', ['base_tree' => $baseTree, 'tree' => [
            ['path' => OpenNightlyRecipePullRequestAction::Path, 'mode' => '100644', 'type' => 'blob', 'content' => $updated],
        ]])->throw()->json('sha');
        if (! $this->contents->isSha($tree)) {
            return;
        }
        $commit = $http->post('/git/commits', ['message' => $title, 'tree' => $tree, 'parents' => [$main]])->throw()->json('sha');
        if (! $this->contents->isSha($commit)) {
            return;
        }
        // Check again immediately before the only destructive write. Never reset to main in between commits.
        if ($http->get($path)->throw()->json() !== $pr || ! $this->untouched($http, $number, $author, $sha)) {
            return;
        }
        // updateRefs is a compare-and-swap: a concurrent push cannot be overwritten, even by force.
        $result = $http->post('https://api.github.com/graphql', [
            'query' => 'mutation($input: UpdateRefsInput!) { updateRefs(input: $input) { clientMutationId } }',
            'variables' => ['input' => ['repositoryId' => $repositoryId, 'refUpdates' => [
                ['name' => 'refs/heads/'.$branch, 'beforeOid' => $sha, 'afterOid' => $commit, 'force' => true],
            ]]],
        ])->throw()->json();
        if (data_get($result, 'errors') !== null || ! is_array(data_get($result, 'data.updateRefs'))) {
            throw new \RuntimeException('GitHub refused the expected-head nightly refresh.');
        }
        $this->comment($http, $number, 'Recreated the failed nightly snapshot on current main as one fresh Gateway App commit. Supersedes '.$sha.' so CI runs against the updated base.');
    }

    /** @phpstan-impure */
    private function untouched(PendingRequest $http, int $number, string $author, string $sha): bool
    {
        $events = $http->get('/issues/'.$number.'/timeline', ['per_page' => 100])->throw()->json();

        return is_array($events) && array_is_list($events) && count($events) < 100
            && array_all($events, static fn (mixed $event): bool => ! in_array(data_get($event, 'event'), ['head_ref_force_pushed', 'closed', 'reopened'], true) && ((data_get($event, 'actor.login') === $author && data_get($event, 'actor.type') === 'Bot')
                // A mention from another issue or PR changes neither this PR nor its branch.
                || data_get($event, 'event') === 'cross-referenced'
                // GitHub's committed event has no actor; its SHA must be the verified App commit.
                || (data_get($event, 'event') === 'committed' && data_get($event, 'sha') === $sha)));
    }

    private function comment(PendingRequest $http, int $number, string $body): void
    {
        try {
            $http->post('/issues/'.$number.'/comments', ['body' => $body])->throw();
        } catch (Throwable) {
            // Recovery already succeeded. A comment failure must not stop PHP maintenance.
        }
    }
}
