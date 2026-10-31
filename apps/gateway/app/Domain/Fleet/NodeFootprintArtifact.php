<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Node;

/**
 * One Gateway-rendered artifact of a Node's Orbit footprint (ADR 0202). The digest comes from the
 * Gateway's own inputs and never reads the Node, so the catch-up can compare it without SSH.
 */
interface NodeFootprintArtifact
{
    /** A stable name, such as `caddy`. */
    public function name(): string;

    /**
     * Whether stored intent assigns this artifact to the Node.
     *
     * @phpstan-impure
     */
    public function applies(Node $node): bool;

    /** The digest of what the Gateway renders for the Node now. */
    public function digest(Node $node): string;
}
