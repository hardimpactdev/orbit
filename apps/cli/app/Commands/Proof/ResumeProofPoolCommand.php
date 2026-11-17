<?php

declare(strict_types=1);

namespace App\Commands\Proof;

use Orbit\Sdk\Requests\Proof\ProofPoolRequest;
use Orbit\Sdk\Requests\Proof\ResumeProofPoolRequest;

final class ResumeProofPoolCommand extends ProofPoolCommand
{
    #[\Override]
    protected $signature = 'proof:pool:resume {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'End a drain of the warm proof copy pool, so it makes warm copies again.';

    protected function request(): ProofPoolRequest
    {
        return new ResumeProofPoolRequest;
    }

    protected function failure(): string
    {
        return 'Could not resume the warm proof copy pool.';
    }
}
