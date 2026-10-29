<?php

declare(strict_types=1);

namespace App\Domain\Instances\Registration;

use App\Models\Node;

interface RegistrationSourceManager
{
    /** @return list<RegistrationSourceFacts> */
    public function inspect(Node $node, string $sourcePath, bool $includeWorktrees): array;

    public function validateRetained(Node $node, RegistrationSourceFacts $facts, string $authoritativePath): void;

    public function validateRelocationRecovery(
        Node $node,
        RegistrationSourceFacts $facts,
        string $candidatePath,
    ): void;
}
