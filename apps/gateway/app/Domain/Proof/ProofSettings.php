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

    public function projectId(): int
    {
        return Config::integer('proof.project');
    }

    /** One proof copy's peak memory in bytes: a Node takes a copy only while its available memory holds one more (ProofCopyHosts). */
    public function copyMemory(): int
    {
        return max(1, Config::integer('proof.copy_memory'));
    }

    /** The warm proof copies that the pool keeps across all CI Nodes; 0 turns the pool off (ProofWarmPool). */
    public function warmCopies(): int
    {
        return max(0, Config::integer('proof.warm_copies'));
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
