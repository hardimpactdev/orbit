<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** Which task VM hosts hold ready copies that are fresh enough for a proof, and why each of the others does not. */
final readonly class ProofHostFreshness
{
    /**
     * @param  list<int>  $hosts  the hosts whose copies include every change of what the image carries up to the merge base
     * @param  array<int,string>  $reasons  host Node id => why its copies are not fresh enough, for each host that has copies recorded or not
     */
    public function __construct(public array $hosts, public array $reasons) {}
}
