<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** What one line of the selection list selects. */
enum ProofRuleKind: string
{
    case Skip = 'skip';
    case Commands = 'commands';
    case Everything = 'everything';
    case None = 'none';
}
