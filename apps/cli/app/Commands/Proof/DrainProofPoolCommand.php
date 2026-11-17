<?php

declare(strict_types=1);

namespace App\Commands\Proof;

use Orbit\Sdk\Requests\Proof\DrainProofPoolRequest;
use Orbit\Sdk\Requests\Proof\ProofPoolRequest;

final class DrainProofPoolCommand extends ProofPoolCommand
{
    #[\Override]
    protected $signature = 'proof:pool:drain {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Destroy the idle warm proof copies and stop refills until proof:pool:resume. Running proofs finish.';

    protected function request(): ProofPoolRequest
    {
        return new DrainProofPoolRequest;
    }

    protected function failure(): string
    {
        return 'Could not drain the warm proof copy pool.';
    }
}
