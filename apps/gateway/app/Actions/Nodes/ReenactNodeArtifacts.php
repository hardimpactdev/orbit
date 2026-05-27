<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Models\Node;

class ReenactNodeArtifacts
{
    /**
     * @param  list<string>  $changed
     * @return list<array<string, string>>
     */
    public function handle(Node $node, array $changed): array
    {
        return [];
    }
}
