<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use Illuminate\Http\Client\PendingRequest;

/** Rebuild only an untouched, failed App snapshot commit whose parent main has superseded. */
final readonly class RefreshNightlyRecipePullRequestAction
{
    public function __construct(private GitHubAppStore $store, private GitHubApi $github, private RepositoryPullRequestAccess $access, private RecipeLoader $loader) {}

    public function execute(PendingRequest $http, GitHubRepository $repository, int $number, string $branch, string $main, string $content, string $title): void
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
        if (! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1
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
            || ! is_string($parent) || preg_match('/\A[0-9a-f]{40}\z/', $parent) !== 1 || $parent === $main) {
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
        $before = $this->contents($http, $parent);
        $after = $this->contents($http, $sha);
        $snapshot = substr($branch, -8).'T000000Z';
        if ($before === null || $after === null) {
            return;
        }
        $old = $this->loader->decode($before)->snapshot;
        $expected = $this->replace($before, $old, $snapshot);
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
        $updated = $this->replace($content, $this->loader->decode($content)->snapshot, $snapshot);
        if ($updated === null) {
            return;
        }
        $baseTree = $http->get('/git/commits/'.$main)->throw()->json('tree.sha');
        if (! is_string($baseTree) || preg_match('/\A[0-9a-f]{40}\z/', $baseTree) !== 1) {
            return;
        }
        $tree = $http->post('/git/trees', ['base_tree' => $baseTree, 'tree' => [
            ['path' => OpenNightlyRecipePullRequestAction::Path, 'mode' => '100644', 'type' => 'blob', 'content' => $updated],
        ]])->throw()->json('sha');
        if (! is_string($tree) || preg_match('/\A[0-9a-f]{40}\z/', $tree) !== 1) {
            return;
        }
        $commit = $http->post('/git/commits', ['message' => $title, 'tree' => $tree, 'parents' => [$main]])->throw()->json('sha');
        if (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/', $commit) !== 1) {
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
        $http->post('/issues/'.$number.'/comments', ['body' => 'Recreated the failed nightly snapshot on current main as one fresh Gateway App commit. Supersedes '.$sha.' so CI runs against the updated base.'])->throw();
    }

    /** @phpstan-impure */
    private function untouched(PendingRequest $http, int $number, string $author, string $sha): bool
    {
        $events = $http->get('/issues/'.$number.'/timeline', ['per_page' => 100])->throw()->json();

        return is_array($events) && array_is_list($events) && count($events) < 100
            && array_all($events, static fn (mixed $event): bool => (data_get($event, 'actor.login') === $author && data_get($event, 'actor.type') === 'Bot')
                // A mention from another issue or PR changes neither this PR nor its branch.
                || data_get($event, 'event') === 'cross-referenced'
                // GitHub's committed event has no actor; its SHA must be the verified App commit.
                || (data_get($event, 'event') === 'committed' && data_get($event, 'sha') === $sha));
    }

    private function contents(PendingRequest $http, string $sha): ?string
    {
        $encoded = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $sha])->throw()->json('content');
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;

        return is_string($content) ? $content : null;
    }

    private function replace(string $content, string $old, string $snapshot): ?string
    {
        $updated = preg_replace('/("snapshot"\s*:\s*")'.preg_quote($old, '/').'(")/', '${1}'.$snapshot.'${2}', $content, 1, $count);

        return is_string($updated) && $count === 1 ? $updated : null;
    }
}
