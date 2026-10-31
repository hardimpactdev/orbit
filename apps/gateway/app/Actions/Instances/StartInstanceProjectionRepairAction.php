<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Native Route repair must own its complete fresh publication DAG. */
final readonly class StartInstanceProjectionRepairAction
{
    public function execute(Instance $instance): Operation
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Native Instance projection repair is unavailable.', 409);
    }
}
