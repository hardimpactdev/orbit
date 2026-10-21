<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\Node;

/** Owns only the raw ingress filter for managed database endpoints. */
interface DatabaseServerFirewall
{
    /** @param list<string> $rules */
    public function reconcile(Node $node, array $rules, bool $expand = false): void;

    /** @param list<string> $rules */
    public function matches(Node $node, array $rules): bool;
}
