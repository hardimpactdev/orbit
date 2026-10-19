<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Opens an authenticated snapshot and Ubuntu pin PR from main; repository rules still decide when it can merge. */
final readonly class OpenNightlyRecipePullRequestAction
{
    public const string Path = 'apps/gateway/resources/machine-software/recipe.json';

    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github, private RecipeLoader $loader, private RefreshNightlyRecipePullRequestAction $refresh, private NightlyRecipeContents $contents) {}

    public function execute(): ?string
    {
        $repository = GitHubRepository::fromOrigin('https://github.com/'.FleetRolloutAlerts::repository())
            ?? throw new RuntimeException('The Orbit repository is invalid.');
        $token = $this->access->token($repository);
        $http = Http::baseUrl('https://api.github.com/repos/'.$repository->owner.'/'.$repository->name)
            ->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
        $head = $http->get('/git/ref/heads/main')->throw()->json('object.sha');
        if (! $this->contents->isSha($head)) {
            throw new RuntimeException('GitHub did not return the main commit.');
        }
        $file = $http->get('/contents/'.self::Path, ['ref' => $head])->throw()->json();
        $content = is_array($file) && is_string($file['content'] ?? null) ? base64_decode($file['content'], true) : false;
        if (! is_string($content) || ! is_string($file['sha'] ?? null)) {
            throw new RuntimeException('GitHub did not return the recipe file.');
        }
        $this->contents->requireDeployed($content);
        $this->refresh->execute($head, $content);
        $recipe = $this->loader->decode($content);
        $snapshot = now('Europe/Amsterdam')->format('Ymd').'T000000Z';
        if ($recipe->snapshot >= $snapshot) {
            return null;
        }
        $branch = 'nightly/recipe-'.substr($snapshot, 0, 8);
        $title = 'Bump machine recipe snapshot to '.$snapshot;
        $draft = new GitHubPullRequestDraft($branch, 'main', $title, 'Advances the Ubuntu snapshot and every Ubuntu pin to the newest authenticated version on all recipe architectures. Artifact pins stay unchanged. The Gateway merges on green Required checks only after verifying its App authorship, one commit, and the exact regenerated snapshot and Ubuntu pin bump. After deployment, existing host image builds gate ordered fleet convergence.');
        $existing = $this->github->pullRequestsByHead($token, $repository, $branch);
        if ($existing !== []) {
            // A closed date PR is an operator decision too; never reopen or overwrite it.
            return $existing[0]->url;
        }
        $created = $http->post('/git/refs', ['ref' => 'refs/heads/'.$branch, 'sha' => $head]);
        if ($created->status() !== 422) {
            $created->throw();
        }
        $branchFile = $http->get('/contents/'.self::Path, ['ref' => $branch])->throw()->json();
        if (! is_array($branchFile) || ! is_string($branchFile['content'] ?? null)) {
            throw new RuntimeException('GitHub did not return the date branch recipe.');
        }
        $onBranch = $this->loader->decode((string) base64_decode($branchFile['content'], true));
        if ($onBranch->snapshot < $snapshot) {
            if (($branchFile['sha'] ?? null) !== $file['sha']) {
                throw new RuntimeException('The date branch recipe changed. Inspect it before retrying.');
            }
            $updated = $this->contents->replace($content, $recipe->snapshot, $snapshot);
            $this->loader->decode($updated);
            $http->put('/contents/'.self::Path, ['branch' => $branch, 'sha' => $file['sha'], 'message' => $title, 'content' => base64_encode($updated)])->throw();
        }

        return $this->github->openPullRequest($token, $repository, $draft)->url;
    }
}
