<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Records a declared workflow's evidence when it finishes inside an open parent. */
interface DirectOperationChildProjection
{
    public function acceptsParent(Operation $parent): bool;

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     */
    public function applyChild(Operation $parent, array $input, array $result): void;
}
