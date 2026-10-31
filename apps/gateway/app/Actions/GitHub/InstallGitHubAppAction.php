<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppData;
use App\Data\GitHub\GitHubAppInstallData;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppRegistration;
use App\Domain\GitHub\GitHubAppStore;

final readonly class InstallGitHubAppAction
{
    public function __construct(
        private GitHubAppStore $store,
    ) {}

    public function handle(string $gatewayUrl, string $name, ?string $owner): GitHubAppInstallData
    {
        $credentials = $this->store->credentials();

        if (! $credentials instanceof GitHubAppCredentials) {
            $state = bin2hex(random_bytes(32));
            $this->store->beginRegistration(new GitHubAppRegistration(
                state: $state,
                name: $name,
                owner: $owner,
                gatewayUrl: $gatewayUrl,
                expiresAt: now()->getTimestamp() + GitHubAppRegistration::LIFETIME_SECONDS,
            ));

            return new GitHubAppInstallData(
                step: 'register',
                url: "{$gatewayUrl}/api/v1/github/app/register?state={$state}",
                accounts: [],
            );
        }

        $data = GitHubAppData::fromStore($this->store);

        return new GitHubAppInstallData(
            step: 'install',
            url: $credentials->installUrl(),
            accounts: array_values(array_filter(array_column($data->installations, 'account'), is_string(...))),
            accountsStatus: $data->installationsStatus,
            observedAt: $data->observedAt,
        );
    }
}
