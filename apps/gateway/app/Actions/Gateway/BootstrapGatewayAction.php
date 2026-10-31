<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Models\Node;

final readonly class BootstrapGatewayAction
{
    public function execute(BootstrapGatewayData $data): Node
    {
        throw new NodeProvisioningException(
            step: 'bootstrap',
            errorCode: 'gateway.bootstrap_unavailable',
            message: 'Gateway bootstrap is unavailable until native Swamp bootstrap is installed.',
            status: 422,
        );
    }
}
