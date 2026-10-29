<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** One terminal rule for App nightlies, independent of checks, evidence, or merge refusals. */
final readonly class RetireNightlyRecipePullRequestsAction
{
    public const int RetrySeconds = 300;

    public function __construct(private NightlyRecipeContents $contents, private RecipeMaintenanceAlerts $alerts) {}

    /**
     * @param  list<GitHubListedPullRequest>  $pulls
     * @return list<GitHubListedPullRequest> PRs still eligible for further work; failed retirements wait for retry.
     */
    public function execute(PendingRequest $http, GitHubRepository $repository, array $pulls, string $author, string $mainContent, int $number): array
    {
        $mainSnapshot = $this->contents->snapshot($mainContent);
        $remaining = [];
        foreach ($pulls as $pull) {
            if ($pull->number !== $number) {
                $remaining[] = $pull;

                continue;
            }
            $snapshot = $this->contents->nightlySnapshot($pull->headRef);
            if ($snapshot === null || $pull->authorLogin !== $author) {
                $remaining[] = $pull;

                continue;
            }
            $key = 'machine-recipe:retire-retry:'.$repository->owner.'/'.$repository->name.':'.$pull->number.':'.$pull->headSha;
            if (Cache::has($key)) {
                continue;
            }
            try {
                $reason = $mainSnapshot >= $snapshot ? "main’s Ubuntu snapshot [{$mainSnapshot}] has reached this PR’s date [{$snapshot}]." : null;
                if ($reason === null) {
                    foreach ($pulls as $newer) {
                        $date = $this->contents->nightlySnapshot($newer->headRef);
                        if ($date === null || $date <= $snapshot || $newer->authorLogin !== $author) {
                            continue;
                        }
                        if ($this->contents->appPull($http->get('/pulls/'.$newer->number)->throw()->json(), $newer->headSha, $newer->headRef, $repository, $author)) {
                            $reason = "a newer App nightly PR is open: {$newer->url}.";

                            break;
                        }
                    }
                }
                if ($reason === null || ! $this->contents->appPull($http->get('/pulls/'.$pull->number)->throw()->json(), $pull->headSha, $pull->headRef, $repository, $author)) {
                    $remaining[] = $pull;

                    continue;
                }
                $http->patch('/pulls/'.$pull->number, ['state' => 'closed'])->throw();
                try {
                    $http->post('/issues/'.$pull->number.'/comments', ['body' => 'Superseded this nightly recipe PR because '.$reason])->throw();
                } catch (Throwable) {
                    // Closing already ended the PR. A comment failure must not re-run retirement.
                }
            } catch (Throwable) {
                Cache::put($key, true, self::RetrySeconds);
                $retrySeconds = self::RetrySeconds;
                $this->alerts->haltedOnce("Nightly recipe PR [{$pull->url}] retirement failed; retrying in {$retrySeconds} seconds.",
                    RecipeMaintenanceAlerts::pullIncident($pull->number, $pull->headSha, 'retirement_failed'));
            }
        }

        return $remaining;
    }
}
