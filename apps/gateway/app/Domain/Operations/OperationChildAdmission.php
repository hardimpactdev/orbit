<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Owning-family validation and intent recording through its normal admission action.
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
interface OperationChildAdmission
{
    /** @param ChildIntent $intent Server-built; never free Swamp input. */
    public function admit(Operation $parent, array $intent): Operation;
}
