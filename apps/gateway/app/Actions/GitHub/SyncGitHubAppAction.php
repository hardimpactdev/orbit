<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppData;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\HttpGitHubAppManagement;
use RuntimeException;

/** Reads the App's installations with an App JWT the Gateway signs, stores the observation and answers like show. */
final readonly class SyncGitHubAppAction
{
    public function __construct(private GitHubAppStore $store, private HttpGitHubAppManagement $github) {}

    public function handle(): GitHubAppData
    {
        $app = $this->store->credentials();
        if ($app === null) {
            throw new ResourceOperationException('github.app_missing', 'The Gateway has no registered GitHub App.', 404);
        }
        try {
            $observation = $this->github->sync($app);
        } catch (RuntimeException) {
            // The last observation stays; show reports its age.
            throw new ResourceOperationException('github.unavailable', 'The Gateway could not read the App installations from GitHub.', 503);
        }
        $this->store->observe($observation);

        return GitHubAppData::fromStore($this->store);
    }
}
