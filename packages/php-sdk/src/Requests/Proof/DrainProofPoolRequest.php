<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Proof;

/** proof:pool:drain: destroys the idle warm proof copies and stops refills until the resume. */
final class DrainProofPoolRequest extends ProofPoolRequest
{
    protected function action(): string
    {
        return 'drain';
    }
}
