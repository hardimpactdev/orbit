<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetRolloutAlerts;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequestDraft;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Infrastructure\MachineSoftware\PhpBuildBump;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Part of the nightly recipe maintenance: opens one PR that moves every Sury PHP pin to Sury's newest build (ADR 0216).
 * Its branch is not a date branch, so the nightly auto-merge never takes it; a maintainer reviews it.
 */
final readonly class OpenPhpBuildPullRequestAction
{
    public const string BranchPrefix = 'nightly/php-build-';

    public function __construct(
        private RepositoryPullRequestAccess $access,
        private GitHubApi $github,
        private RecipeLoader $loader,
        private PhpBuildBump $bump,
    ) {}

    public function execute(): ?string
    {
        $repository = GitHubRepository::fromOrigin('https://github.com/'.FleetRolloutAlerts::repository())
            ?? throw new RuntimeException('The Orbit repository is invalid.');
        $token = $this->access->token($repository);
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
        $current = $this->loader->decode($content);
        $updated = $this->bump->bump($content);
        if ($updated === $content) {
            return null;
        }
        // The loader refuses a partial bump: every pin of one PHP version moves together.
        $bumped = $this->loader->decode($updated);
        $releases = implode(', ', array_map(static fn ($build): string => 'PHP '.$build->phpVersion, $bumped->php));
        // Named after the PHP pins alone, so a later snapshot date does not open a second PR for the same build.
        $pins = [];
        foreach ($bumped->php as $build) {
            foreach ($build->packages as $name) {
                foreach ($bumped->tools[$name]->artifacts as $architecture => $artifact) {
                    $pins[] = "{$name} {$bumped->tools[$name]->version} {$architecture} {$artifact->digest}";
                }
            }
        }
        $pins = array_values(array_unique($pins));
        sort($pins);
        $branch = self::BranchPrefix.substr(hash('sha256', implode("\n", $pins)), 0, 12);
        $existing = $this->github->pullRequestsByHead($token, $repository, $branch);
        if ($existing !== []) {
            // A closed PR for the same pins is an operator decision; never reopen or overwrite it.
            return $existing[0]->url;
        }
        $title = 'Bump the PHP build to '.$releases;
        $created = $http->post('/git/refs', ['ref' => 'refs/heads/'.$branch, 'sha' => $head]);
        if ($created->status() !== 422) {
            $created->throw();
        }
        $branchFile = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $branch])->throw()->json();
        if (! is_array($branchFile) || ! is_string($branchFile['content'] ?? null)) {
            throw new RuntimeException('GitHub did not return the PHP build branch recipe.');
        }
        if (base64_decode($branchFile['content'], true) !== $updated) {
            if (($branchFile['sha'] ?? null) !== $file['sha']) {
                throw new RuntimeException('The PHP build branch recipe changed. Inspect it before retrying.');
            }
            $http->put('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['branch' => $branch, 'sha' => $file['sha'], 'message' => $title, 'content' => base64_encode($updated)])->throw();
        }
        $body = 'Moves every Sury PHP pin of the machine software recipe to Sury\'s newest build, from '
            .implode(', ', array_map(static fn ($build): string => 'PHP '.$build->phpVersion, $current->php))
            .". Each package of a PHP version changes together, with its URL and SHA-256 digest from Sury's signed package index.\n\n"
            .'A maintainer reviews and merges this PR; the nightly auto-merge never takes it. After merge, the task VM hosts rebuild their base image, '
            .'nightly recipe maintenance converges the Nodes, and hosted CI installs the new build. Update the self-hosted runners as '
            .'docs/reference/implementation-loop.md#one-php-build-in-ci describes.';

        return $this->github->openPullRequest($token, $repository, new GitHubPullRequestDraft($branch, 'main', $title, $body))->url;
    }
}
