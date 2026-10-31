<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Operations\DirectOperation;
use App\Domain\TaskVms\TaskVmSettings;
use Illuminate\Support\Facades\Http;

/** Credential-free, read-only recipe PR candidates for the Swamp schedule. */
final readonly class RecipePrPlanAction
{
    public function __construct(private FleetServingRelease $serving, private GatewayServingHost $gateway, private TaskVmSettings $settings,
        private GitHubAppStore $store, private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    /** @return array<string, mixed> */
    public function execute(): array
    {
        if (! $this->settings->enabled || $this->serving->read()['state'] !== FleetServingRelease::Adopted || $this->serving->supersedes()) {
            return ['status' => 'disabled'];
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/hardimpactdev/orbit');
        $credentials = $this->store->credentials();
        if ($repository === null || $credentials === null || $this->gateway->nodeId() === null) {
            return ['status' => 'unavailable'];
        }
        $readToken = $this->access->readToken($repository);
        $pulls = $this->github->openPullRequests($readToken, $repository);
        $http = Http::baseUrl('https://api.github.com/repos/hardimpactdev/orbit')->withToken($readToken)->acceptJson()->withoutRedirecting()->timeout(15)->connectTimeout(5);
        $main = $http->get('/git/ref/heads/main')->throw()->json('object.sha');
        if (! is_string($main) || preg_match('/\A[0-9a-f]{40}\z/D', $main) !== 1) {
            throw new \RuntimeException('The recipe main revision is unavailable.');
        }
        $blob = $http->get('/contents/apps/gateway/resources/machine-software/recipe.json', ['ref' => $main])->throw()->json('sha');
        if (! is_string($blob) || preg_match('/\A[0-9a-f]{40}\z/D', $blob) !== 1) {
            throw new \RuntimeException('The recipe content revision is unavailable.');
        }
        $candidates = [];
        foreach ($pulls as $pull) {
            if ($pull->authorLogin === $credentials->slug.'[bot]' && preg_match('/\Anightly\/(recipe-[0-9]{8}|artifacts-[0-9a-f]{12})\z/D', $pull->headRef) === 1) {
                $check = config()->string('orbit.gateway_releases.check');
                $checksToken = $this->access->checksToken($repository);
                $runs = $checksToken === null ? [] : $this->github->checkRuns($checksToken, $repository, $pull->headSha, $check);
                $observations = array_map(static fn ($run): array => [$run->id, $run->name, $run->status, $run->conclusion, $run->headSha, $run->appSlug], $runs);
                sort($observations);
                $candidates[] = ['checks' => ($checksToken === null ? RequiredCheckState::Unreadable : RequiredCheckState::of($runs, $pull->headSha, $check))->value,
                    'checks_revision' => hash('sha256', json_encode($observations, JSON_THROW_ON_ERROR)),
                    'policy_revision' => hash('sha256', json_encode([$pull->draft, $pull->baseRef, $pull->headRepository], JSON_THROW_ON_ERROR)), 'number' => $pull->number, 'head_sha' => $pull->headSha, 'branch' => $pull->headRef,
                    'variant' => str_starts_with($pull->headRef, 'nightly/recipe-') ? 'snapshot' : 'artifacts'];
            }
        }

        return ['status' => 'ready', 'writer' => app(DirectOperation::class)->writer('fleet.recipe_pr'),
            'base_revision' => $main, 'recipe_revision' => hash('sha256', $blob),
            'serving_recipe_revision' => hash_file('sha256', resource_path('machine-software/recipe.json')),
            'gateway_node_id' => $this->gateway->nodeId(), 'date' => now('Europe/Amsterdam')->format('Ymd'), 'pulls' => $candidates];
    }
}
