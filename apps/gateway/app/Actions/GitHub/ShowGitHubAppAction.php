<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppData;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;

final readonly class ShowGitHubAppAction
{
    public function __construct(private GitHubAppStore $store) {}

    public function handle(): GitHubAppData
    {
        if ($this->store->credentials() === null && $this->store->registration() === null && $this->store->exchange() === null) {
            throw new ResourceOperationException('github.app_missing', 'The Gateway has no GitHub App. Run github:app:install.', 404);
        }

        return GitHubAppData::fromStore($this->store);
    }
}
