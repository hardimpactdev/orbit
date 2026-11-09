<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Models\Operation;

/**
 * What `node:update` did (docs/cli/node/update.mmd): the saved Node, or the Operation of the run that prepares the
 * apps folder. `unchanged` is true when every value already equalled the stored one, so nothing was written.
 */
final readonly class UpdateNodeResult
{
    public const string Unchanged = 'node.unchanged';

    public function __construct(public Node|Operation $result, public bool $unchanged = false) {}
}
