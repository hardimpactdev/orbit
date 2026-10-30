<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

interface DirectOperationProjection
{
    /** Safe admitted input only. Never return raw secrets or transport credentials.
     * @return array<string, mixed>
     */
    public function input(Operation $operation): array;

    /** Validate domain results, then call DirectOperation::project.
     * @param  array<string, mixed>  $data
     */
    public function apply(Operation $operation, array $data): Operation;
}
