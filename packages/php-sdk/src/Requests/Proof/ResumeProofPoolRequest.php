<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Proof;

/** proof:pool:resume: ends a drain and refills the warm proof copy pool. */
final class ResumeProofPoolRequest extends ProofPoolRequest
{
    protected function action(): string
    {
        return 'resume';
    }
}
