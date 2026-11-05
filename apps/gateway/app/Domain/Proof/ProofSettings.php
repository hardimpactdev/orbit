<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\GitHub\GitHubRepository;
use Illuminate\Support\Facades\Config;
use LogicException;

/** The proof settings of `config/proof.php`. */
final readonly class ProofSettings
{
    public function executionEnabled(): bool
    {
        return Config::boolean('proof.execution');
    }

    public function repository(): GitHubRepository
    {
        return GitHubRepository::fromOrigin('https://github.com/'.Config::string('proof.repository'))
            ?? throw new LogicException('proof.repository must be owner/name.');
    }

    public function baseBranch(): string
    {
        return Config::string('proof.base_branch');
    }

    public function label(): string
    {
        return Config::string('proof.label');
    }
}
