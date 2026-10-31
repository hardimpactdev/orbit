<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\TransferInstanceData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Cross-Node transfer needs a native archive and publication composition contract. */
final readonly class StartInstanceTransferAction
{
    public function execute(Instance $instance, TransferInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Native cross-Node Instance transfer is unavailable.', 409);
    }
}
