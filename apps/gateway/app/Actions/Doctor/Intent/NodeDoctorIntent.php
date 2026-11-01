<?php

declare(strict_types=1);

namespace App\Actions\Doctor\Intent;

use App\Models\Node;

/** Only the Node lifecycle expectation; provisioning owns configuration verification. */
final readonly class NodeDoctorIntent
{
    /** @return array<string,list<array<string,mixed>>> */
    public function execute(Node $node): array
    {
        return ['node_checks' => [['status' => $node->status->value]]];
    }
}
