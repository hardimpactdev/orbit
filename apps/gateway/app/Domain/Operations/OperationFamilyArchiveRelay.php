<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Pure family binding from the recorded child plan to a named binary reader. */
interface OperationFamilyArchiveRelay
{
    /**
     * @param  array<string,mixed>  $run  Original admitted input; never hydrated payloads.
     * @return array{source_transport:string,source_name:string,read:array{name:string,input:array<string,mixed>},max_bytes:int}
     */
    public function archiveRelay(Operation $operation, array $run): array;
}
