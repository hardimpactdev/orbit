<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** A published observer delivery admitted by the trusted Gateway, never by candidate code. */
interface ProofObserverRelease
{
    public function forCommit(string $commit): string;

    public function forGateway(): string;
}
