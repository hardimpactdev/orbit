<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Models\Node;

final class DoctorIssueNodeResolver
{
    public function resolve(DoctorIssue $issue): ?Node
    {
        if ($issue->node === null) {
            return null;
        }

        $node = Node::query()->where('name', $issue->node)->first();

        return $node instanceof Node ? $node : null;
    }
}
