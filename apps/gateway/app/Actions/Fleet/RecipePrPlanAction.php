<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\TaskVms\TaskVmSettings;

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
        $pulls = $this->github->openPullRequests($this->access->readToken($repository), $repository);
        $candidates = [];
        foreach ($pulls as $pull) {
            if ($pull->authorLogin === $credentials->slug.'[bot]' && preg_match('/\Anightly\/(recipe-[0-9]{8}|artifacts-[0-9a-f]{12})\z/D', $pull->headRef) === 1) {
                $candidates[] = ['number' => $pull->number, 'head_sha' => $pull->headSha, 'branch' => $pull->headRef,
                    'variant' => str_starts_with($pull->headRef, 'nightly/recipe-') ? 'snapshot' : 'artifacts'];
            }
        }

        return ['status' => 'ready', 'gateway_node_id' => $this->gateway->nodeId(), 'date' => now('Europe/Amsterdam')->format('Ymd'), 'pulls' => $candidates];
    }
}
