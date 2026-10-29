<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AdmitNodeBootstrapAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Models\Operation;

/** The kit supplies exact parent-owned intent and stamps the child relation. */
final readonly class NodeBootstrapChildAdmission implements OperationChildAdmission
{
    public function __construct(private AdmitNodeBootstrapAction $admission) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        return $this->admission->execute($parent, $intent);
    }
}
