<?php

declare(strict_types=1);

namespace App\Commands\GitHub;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\GitHub\SyncGitHubAppRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;

final class SyncGitHubAppCommand extends GitHubCommand
{
    #[\Override]
    protected $signature = 'github:app:sync {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Read the GitHub App installations from GitHub now and show the App.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $app = $this->sendWithProgress($connector, new SyncGitHubAppRequest, GitHubAppResponse::class,
            ['Refresh GitHub App', 'Reading installations from GitHub', 'Read installations from GitHub']);
        if (! $app instanceof GitHubAppResponse) {
            return self::FAILURE;
        }
        if ($this->option('json') === true) {
            $this->writeJson($app->toArray());

            return self::SUCCESS;
        }
        $this->writeApp($app, 'GitHub App.');
        $this->writeHumanMessage("Request ID: {$app->requestId}");

        return self::SUCCESS;
    }
}
