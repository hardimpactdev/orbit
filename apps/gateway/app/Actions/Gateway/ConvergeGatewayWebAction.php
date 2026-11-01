<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Domain\Nodes\NodeProvisioningException;

final readonly class ConvergeGatewayWebAction
{
    public function execute(): never
    {
        throw new NodeProvisioningException(
            step: 'gateway-web',
            errorCode: 'gateway.web_unavailable',
            message: 'Manual Gateway web convergence is unavailable until its native Swamp workflow is installed.',
            status: 422,
        );
    }
}
