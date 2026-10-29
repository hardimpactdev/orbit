<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\MachineSoftware\RecipeArtifactsBump;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** One signed-index update PR for every recipe package set that has an authenticated artifact updater. */
final readonly class OpenRecipeArtifactsPullRequestAction
{
    public const string BranchPrefix = 'nightly/artifacts-';

    public function __construct(
        private GitHubApi $github,
        private RecipeLoader $loader,
        private RecipeArtifactsBump $bump,
        private NightlyRecipeContents $contents,
    ) {}

    public function execute(#[\SensitiveParameter] string $token): ?string
    {
        $repository = GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit')
            ?? throw new RuntimeException('The Orbit repository is invalid.');
        $http = Http::baseUrl('https://api.github.com/repos/'.$repository->owner.'/'.$repository->name)
            ->withToken($token)->acceptJson()->timeout(15)->connectTimeout(5);
        $head = $http->get('/git/ref/heads/main')->throw()->json('object.sha');
        if (! is_string($head) || preg_match('/\A[0-9a-f]{40}\z/', $head) !== 1) {
            throw new RuntimeException('GitHub did not return the main commit.');
        }
        $file = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $head])->throw()->json();
        $content = is_array($file) && is_string($file['content'] ?? null) ? base64_decode($file['content'], true) : false;
        if (! is_string($content) || ! is_string($file['sha'] ?? null)) {
            throw new RuntimeException('GitHub did not return the recipe file.');
        }
        $this->contents->requireDeployed($content);
        $this->loader->decode($content);
        $updated = $this->bump->bump($content);
        if ($updated === $content) {
            return null;
        }
        $this->bump->validateChange($content, $updated);
        $this->loader->decode($updated);
        $branch = self::BranchPrefix.$this->bump->fingerprint($updated);
        $existing = $this->github->pullRequestsByHead($token, $repository, $branch);
        if ($existing !== []) {
            // A closed PR for the same pins is an operator decision; never reopen or overwrite it.
            return $existing[0]->url;
        }
        $title = 'Bump the recipe artifacts (Sury PHP and Incus LTS)';
        $created = $http->post('/git/refs', ['ref' => 'refs/heads/'.$branch, 'sha' => $head]);
        if ($created->status() !== 422) {
            $created->throw();
        }
        $branchFile = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $branch])->throw()->json();
        if (! is_array($branchFile) || ! is_string($branchFile['content'] ?? null)) {
            throw new RuntimeException('GitHub did not return the recipe artifact branch recipe.');
        }
        if (base64_decode($branchFile['content'], true) !== $updated) {
            if (($branchFile['sha'] ?? null) !== $file['sha']) {
                throw new RuntimeException('The recipe artifact branch recipe changed. Inspect it before retrying.');
            }
            $http->put('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['branch' => $branch, 'sha' => $file['sha'], 'message' => $title, 'content' => base64_encode($updated)])->throw();
        }
        $body = "Updates complete Sury PHP and Incus LTS package sets from authenticated signed indexes. URLs, native versions and digests move together.\n\n"
            .'The Gateway App merges on green Required checks only after checking its signed single commit, the recipe-only change, the pin-derived branch name, and a signed-index proof from the parent recipe. '
            .'Image builds gate fleet convergence. Incus changes always require explicit convergence on a drained host; fleet convergence holds only Incus, records the held pins and alerts once per Node per held version while applying all other changes.';

        return $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($branch, 'main', $title, $body))->url;
    }
}
